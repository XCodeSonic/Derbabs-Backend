<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\Controller;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Person;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\NotificationService;
use App\Mail\OTPMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const ATTEMPTS_PER_CYCLE = 5;
    private const FIRST_LOCKOUT_MINUTES  = 10;
    private const SECOND_LOCKOUT_MINUTES = 60;

    // ============================================================
    // LOGIN
    // ============================================================
    public function login(Request $request)
    {
        $validator = validator($request->all(), [
            'email' => 'nullable|string',
            'username' => 'nullable|string',
            'userId' => 'nullable|string',
            'user_id' => 'nullable|string',
            'emailOrUsername' => 'nullable|string',
            'password' => 'required|string',
            'role' => 'nullable|string',
            'otp_code' => 'nullable|string|size:6',
            'require_otp' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $identifier = $request->input('emailOrUsername')
            ?? $request->input('email')
            ?? $request->input('username')
            ?? $request->input('userId')
            ?? $request->input('user_id');

        if (empty($identifier)) {
            return response()->json([
                'success' => false,
                'message' => 'Email or username is required',
                'error_code' => 'MISSING_IDENTIFIER',
            ], 422);
        }

        $identifier = trim($identifier);

        $user = User::with(['person', 'roles', 'customer', 'employee'])
            ->where('username', $identifier)
            ->orWhereHas('person', function ($query) use ($identifier) {
                $query->where('email', $identifier);
            })
            ->first();

        if ($user && $user->isLoginLocked()) {
            $secondsLeft = $user->lockoutSecondsRemaining();
            return response()->json([
                'success' => false,
                'message' => 'Too many failed login attempts. Please try again in '
                    . ceil($secondsLeft / 60) . ' minute(s).',
                'error_code' => 'ACCOUNT_LOCKED',
                'locked_until' => $user->locked_until->toIso8601String(),
                'seconds_left' => $secondsLeft,
                'attempts_used' => $user->failed_login_attempts,
                'max_attempts' => self::ATTEMPTS_PER_CYCLE,
                'lock_level' => $user->lock_level,
                'lockout_minutes' => $user->lock_level >= 2
                    ? self::SECOND_LOCKOUT_MINUTES
                    : self::FIRST_LOCKOUT_MINUTES,
            ], 429);
        }

        if (!$user) {
            $this->recordFailedLogin($identifier, $request);

            return response()->json([
                'success' => false,
                'message' => 'Incorrect Email or Username. Please check and try again.',
                'error_code' => 'INVALID_EMAIL',
                'field' => 'username',
            ], 401);
        }

        if (!Hash::check($request->password, $user->password)) {
            $this->recordFailedLogin($identifier, $request);
            $info = $user->registerFailedLogin($request->ip());

            if ($info['locked']) {
                return response()->json([
                    'success' => false,
                    'message' => $info['lock_level'] >= 2
                        ? 'Too many failed login attempts. Your account is locked for 1 hour.'
                        : 'Too many failed login attempts. Your account is locked for 10 minutes.',
                    'error_code' => 'ACCOUNT_LOCKED',
                    'locked_until' => $info['locked_until'],
                    'seconds_left' => $info['seconds_left'],
                    'attempts_used' => $info['attempts'],
                    'max_attempts' => self::ATTEMPTS_PER_CYCLE,
                    'lock_level' => $info['lock_level'],
                    'lockout_minutes' => $info['lockout_minutes'],
                ], 429);
            }

            return response()->json([
                'success' => false,
                'message' => 'Incorrect Password. '
                    . $info['attempts_left'] . ' attempt(s) left before lockout.',
                'error_code' => 'INVALID_PASSWORD',
                'field' => 'password',
                'attempts_left' => $info['attempts_left'],
                'attempts_used' => $info['attempts'],
                'max_attempts' => self::ATTEMPTS_PER_CYCLE,
            ], 401);
        }

        // ============================================================
        // BANNED CHECK
        // ============================================================
        if (($user->is_banned ?? false) === true) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been banned. Please contact support.',
                'error_code' => 'ACCOUNT_BANNED',
            ], 403);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Account is inactive. Please contact support.',
                'error_code' => 'ACCOUNT_INACTIVE',
            ], 403);
        }

        $requestedRole = $request->input('role');
        $userRole = $this->primaryRole($user);
        $isAdmin = in_array($userRole, ['admin', 'super-admin'], true);
        $role = $requestedRole ?: $userRole;

        if (!$isAdmin) {
            if ($role === 'customer' && !$user->customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'No customer account found for this user',
                    'error_code' => 'ROLE_MISMATCH',
                ], 401);
            }

            if ($role === 'employee' && !$user->employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'No employee account found for this user',
                    'error_code' => 'ROLE_MISMATCH',
                ], 401);
            }
        }

        if ($request->boolean('require_otp')) {
            $otpResult = $this->verifyOrSendLoginOtp($user, (string) $request->input('otp_code', ''));
            if ($otpResult !== true) return $otpResult;
        }

        $user->clearFailedLogins();

        $token = $user->createToken('auth_token')->plainTextToken;
        $user->update(['last_login_at' => now()]);
        $this->recordLogin($user, $request);
        $user->load(['person', 'roles', 'customer', 'employee']);

        return $this->ok([
            'token' => $token,
            'user' => $this->payload($user),
            'role' => $userRole === 'super-admin' ? 'admin' : $userRole,
        ], 'Login successful');
    }

    // ============================================================
    // ADMIN LOGIN
    // ============================================================
    public function adminLogin(Request $request)
    {
        $validator = validator($request->all(), [
            'email' => 'nullable|string',
            'username' => 'nullable|string',
            'userId' => 'nullable|string',
            'user_id' => 'nullable|string',
            'emailOrUsername' => 'nullable|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $identifier = $request->input('emailOrUsername')
            ?? $request->input('email')
            ?? $request->input('username')
            ?? $request->input('userId')
            ?? $request->input('user_id');
        $identifier = trim((string) $identifier);

        $user = User::with(['person', 'roles'])
            ->where('username', $identifier)
            ->orWhereHas('person', fn($q) => $q->where('email', $identifier))
            ->first();

        if ($user && $user->isLoginLocked()) {
            $secondsLeft = $user->lockoutSecondsRemaining();
            return response()->json([
                'success' => false,
                'message' => 'Too many failed login attempts. Please try again in '
                    . ceil($secondsLeft / 60) . ' minute(s).',
                'error_code' => 'ACCOUNT_LOCKED',
                'locked_until' => $user->locked_until->toIso8601String(),
                'seconds_left' => $secondsLeft,
                'lock_level' => $user->lock_level,
                'max_attempts' => self::ATTEMPTS_PER_CYCLE,
            ], 429);
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Incorrect Email or Username.',
                'error_code' => 'INVALID_EMAIL',
            ], 401);
        }

        if (!Hash::check($request->password, $user->password)) {
            $info = $user->registerFailedLogin($request->ip());
            if ($info['locked']) {
                return response()->json([
                    'success' => false,
                    'message' => $info['lock_level'] >= 2
                        ? 'Too many failed attempts. Locked for 1 hour.'
                        : 'Too many failed attempts. Locked for 10 minutes.',
                    'error_code' => 'ACCOUNT_LOCKED',
                    'locked_until' => $info['locked_until'],
                    'seconds_left' => $info['seconds_left'],
                    'lock_level' => $info['lock_level'],
                    'lockout_minutes' => $info['lockout_minutes'],
                ], 429);
            }
            return response()->json([
                'success' => false,
                'message' => 'Incorrect Password. ' . $info['attempts_left'] . ' attempt(s) left.',
                'error_code' => 'INVALID_PASSWORD',
                'attempts_left' => $info['attempts_left'],
                'max_attempts' => self::ATTEMPTS_PER_CYCLE,
            ], 401);
        }

        // ============================================================
        // BANNED CHECK
        // ============================================================
        if (($user->is_banned ?? false) === true) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been banned. Please contact support.',
                'error_code' => 'ACCOUNT_BANNED',
            ], 403);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Account is inactive. Please contact support.',
                'error_code' => 'ACCOUNT_INACTIVE',
            ], 403);
        }

        if (!$user->roles->contains(fn($role) => in_array($role->slug, ['admin', 'super-admin'], true))) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Admin access required',
                'error_code' => 'ROLE_MISMATCH',
            ], 403);
        }

        $user->clearFailedLogins();

        $token = $user->createToken('admin-token')->plainTextToken;
        $user->update(['last_login_at' => now()]);
        $this->recordLogin($user, $request);
        $user->load(['person', 'roles']);

        return $this->ok([
            'token' => $token,
            'user' => $this->payload($user),
            'role' => 'admin',
        ], 'Admin login successful');
    }

    // ============================================================
    // EMPLOYEE LOGIN (Attendance Tracking / Mobile)
    // ============================================================
    public function employeeLogin(Request $request)
    {
        $validator = validator($request->all(), [
            'employee_code' => 'nullable|string',
            'username' => 'nullable|string',
            'userId' => 'nullable|string',
            'user_id' => 'nullable|string',
            'email' => 'nullable|string',
            'emailOrUsername' => 'nullable|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $identifier = $request->input('employee_code')
            ?? $request->input('emailOrUsername')
            ?? $request->input('email')
            ?? $request->input('username')
            ?? $request->input('userId')
            ?? $request->input('user_id');
        $identifier = trim((string) $identifier);

        $employee = Employee::with(['user.person', 'user.roles', 'person', 'department', 'position'])
            ->where('employee_code', $identifier)
            ->first();

        if (!$employee) {
            $employee = Employee::with(['user.person', 'user.roles', 'person', 'department', 'position'])
                ->whereHas('user', function ($query) use ($identifier) {
                    $query->where('username', $identifier)
                        ->orWhereHas('person', fn($q) => $q->where('email', $identifier));
                })
                ->first();
        }

        $user = $employee?->user;

        if ($user && $user->isLoginLocked()) {
            $secondsLeft = $user->lockoutSecondsRemaining();
            return response()->json([
                'success' => false,
                'message' => 'Too many failed login attempts. Please try again in '
                    . ceil($secondsLeft / 60) . ' minute(s).',
                'error_code' => 'ACCOUNT_LOCKED',
                'locked_until' => $user->locked_until->toIso8601String(),
                'seconds_left' => $secondsLeft,
                'lock_level' => $user->lock_level,
                'max_attempts' => self::ATTEMPTS_PER_CYCLE,
            ], 429);
        }

        if (!$employee || !$user) {
            return response()->json([
                'success' => false,
                'message' => 'Incorrect Employee Code, Email, or Username.',
                'error_code' => 'INVALID_EMAIL',
            ], 401);
        }

        if (!Hash::check($request->password, $user->password)) {
            $info = $user->registerFailedLogin($request->ip());
            if ($info['locked']) {
                return response()->json([
                    'success' => false,
                    'message' => $info['lock_level'] >= 2
                        ? 'Too many failed attempts. Locked for 1 hour.'
                        : 'Too many failed attempts. Locked for 10 minutes.',
                    'error_code' => 'ACCOUNT_LOCKED',
                    'locked_until' => $info['locked_until'],
                    'seconds_left' => $info['seconds_left'],
                    'lock_level' => $info['lock_level'],
                    'lockout_minutes' => $info['lockout_minutes'],
                ], 429);
            }
            return response()->json([
                'success' => false,
                'message' => 'Incorrect Password. ' . $info['attempts_left'] . ' attempt(s) left.',
                'error_code' => 'INVALID_PASSWORD',
                'attempts_left' => $info['attempts_left'],
                'max_attempts' => self::ATTEMPTS_PER_CYCLE,
            ], 401);
        }

        // ============================================================
        // BANNED CHECK
        // ============================================================
        if (($user->is_banned ?? false) === true) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been banned. Please contact support.',
                'error_code' => 'ACCOUNT_BANNED',
            ], 403);
        }

        // ============================================================
        // ⭐ #2 — INACTIVE / TERMINATED EMPLOYEE CHECK
        // Employees whose status is "inactive" or "terminated" cannot log in,
        // even if their linked user account still exists.
        // ============================================================
        $status = strtolower((string) $employee->status) ?: 'active';
        $allowedEmployeeStatuses = ['active', 'on_leave', 'onleave', 'on-leave'];
        if (!in_array($status, $allowedEmployeeStatuses, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Your employee account is ' . $employee->status
                    . '. Please contact an administrator.',
                'error_code' => 'ACCOUNT_INACTIVE',
                'employee_status' => $employee->status,
            ], 403);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Account is inactive. Please contact your administrator.',
                'error_code' => 'ACCOUNT_INACTIVE',
            ], 403);
        }

        $user->clearFailedLogins();

        $user->update(['last_login_at' => now()]);
        $this->recordLogin($user, $request);
        $token = $user->createToken('employee-token')->plainTextToken;

        return $this->ok([
            'token' => $token,
            'employee' => $employee,
            'user' => $this->payload($user),
            'role' => 'employee',
        ], 'Employee login successful');
    }

    // ============================================================
    // PUBLIC: CHECK LOCKOUT STATUS
    // ============================================================
    public function loginStatus(Request $request)
    {
        $validator = validator($request->all(), [
            'identifier' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $identifier = trim($request->input('identifier'));

        $user = User::where('username', $identifier)
            ->orWhereHas('person', fn($q) => $q->where('email', $identifier))
            ->first();

        if (!$user) {
            return response()->json([
                'success' => true,
                'locked' => false,
                'attempts_used' => 0,
                'attempts_left' => self::ATTEMPTS_PER_CYCLE,
                'max_attempts' => self::ATTEMPTS_PER_CYCLE,
                'lock_level' => 0,
            ]);
        }

        if ($user->isLoginLocked()) {
            return response()->json([
                'success' => false,
                'locked' => true,
                'message' => 'Account locked. Please wait.',
                'error_code' => 'ACCOUNT_LOCKED',
                'locked_until' => $user->locked_until->toIso8601String(),
                'seconds_left' => $user->lockoutSecondsRemaining(),
                'attempts_used' => $user->failed_login_attempts,
                'max_attempts' => self::ATTEMPTS_PER_CYCLE,
                'lock_level' => $user->lock_level,
            ], 200);
        }

        return response()->json([
            'success' => true,
            'locked' => false,
            'attempts_used' => $user->failed_login_attempts ?? 0,
            'attempts_left' => max(0, self::ATTEMPTS_PER_CYCLE - ($user->failed_login_attempts ?? 0)),
            'max_attempts' => self::ATTEMPTS_PER_CYCLE,
            'lock_level' => $user->lock_level ?? 0,
        ]);
    }

    // ============================================================
    // REGISTER CUSTOMER
    // ============================================================
    public function registerCustomer(Request $request)
    {
        $validator = validator($request->all(), [
            'first_name' => 'required|string|max:80',
            'last_name' => 'required|string|max:80',
            'username' => 'required|string|min:3|max:50|regex:/^[a-zA-Z0-9_]+$/|unique:users,username',
            'email' => 'required|email|max:120|unique:persons,email',
            'password' => [
                'required',
                'string',
                'min:8',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'regex:/[^A-Za-z0-9]/',
                'confirmed',
            ],
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        return DB::transaction(function () use ($request) {
            $person = Person::create([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => strtolower(trim($request->email)),
                'phone' => $request->phone ?? null,
                'address_line_1' => $request->address ?? null,
                'country' => 'Philippines',
            ]);

            $user = User::create([
                'person_id' => $person->person_id,
                'username' => strtolower(trim($request->username)),
                'password' => Hash::make($request->password),
                'is_active' => true,
            ]);

            $role = Role::where('slug', 'customer')->first();
            if ($role) $user->roles()->sync([$role->role_id]);

            $customer = Customer::create([
                'person_id' => $person->person_id,
                'user_id' => $user->user_id,
                'customer_code' => 'CUS-' . str_pad($user->user_id, 5, '0', STR_PAD_LEFT),
                'is_active' => true,
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return $this->ok([
                'token' => $token,
                'user' => $this->payload($user->load(['person', 'roles', 'customer'])),
                'customer' => $customer,
                'role' => 'customer',
            ], 'Customer registered successfully');
        });
    }

    // ============================================================
    // FORGOT PASSWORD — STEP 1
    // ============================================================
    public function forgotPassword(Request $request)
    {
        $validator = validator($request->all(), [
            'user_id' => 'required|string',
            'email' => 'required|email|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $identifier = trim((string) $request->input('user_id'));
        $providedEmail = strtolower(trim((string) $request->input('email')));

        $user = User::with(['person'])
            ->where('username', $identifier)
            ->orWhereHas('person', fn($q) => $q->where('email', $identifier))
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User account not found.',
                'errors' => ['user_id' => ['No account found with this Username or Email.']],
            ], 404);
        }

        $registeredEmail = strtolower(trim((string) ($user->person?->email ?? $user->username)));

        if (!$registeredEmail || !filter_var($registeredEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'success' => false,
                'message' => 'This account has no valid registered email.',
                'errors' => ['email' => ['No valid registered email found.']],
            ], 422);
        }

        if ($providedEmail !== $registeredEmail) {
            return response()->json([
                'success' => false,
                'message' => 'The email you entered does not match our records.',
                'errors' => ['email' => ['Email does not match the registered email.']],
            ], 422);
        }

        $otp = (string) random_int(100000, 999999);
        Cache::put('password-reset-otp:' . $user->user_id, Hash::make($otp), now()->addMinutes(10));

        try {
            $this->sendOtpEmail($registeredEmail, $otp, 'Dear Ba\'bs Catering Password Reset OTP');
        } catch (\Throwable $e) {
            Log::error('Forgot password OTP send failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to send OTP email. Please try again later.',
                'errors' => ['email' => ['Email sending failed.']],
            ], 500);
        }

        $payload = [
            'user_id' => $user->username,
            'email' => $registeredEmail,
            'expires_in_minutes' => 10,
        ];
        if (app()->environment(['local', 'development', 'testing'])) {
            $payload['debug_otp'] = $otp;
        }

        return response()->json([
            'success' => true,
            'message' => 'Password reset OTP sent to your registered email.',
            'data' => $payload,
        ], 200);
    }

    // ============================================================
    // FORGOT PASSWORD — STEP 2
    // ============================================================
    public function verifyResetOtp(Request $request)
    {
        $validator = validator($request->all(), [
            'user_id' => 'required|string',
            'otp_code' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $identifier = $request->user_id;

        $user = User::where('username', $identifier)
            ->orWhereHas('person', fn($q) => $q->where('email', $identifier))
            ->first();

        $cachedOtp = Cache::get('password-reset-otp:' . $user?->user_id);

        if (!$user || !$cachedOtp || !Hash::check($request->otp_code, $cachedOtp)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP. Please request a new code.',
                'errors' => ['otp_code' => ['Invalid or expired OTP code.']],
            ], 422);
        }

        Cache::forget('password-reset-otp:' . $user->user_id);

        $resetToken = Str::random(64);
        Cache::put('password-reset-token:' . $user->user_id, $resetToken, now()->addHours(24));

        return response()->json([
            'success' => true,
            'message' => 'OTP verified successfully.',
            'data' => [
                'reset_token' => $resetToken,
                'expires_in_hours' => 24,
            ],
        ], 200);
    }

    public function resendResetOtp(Request $request)
    {
        return $this->forgotPassword($request);
    }

    // ============================================================
    // FORGOT PASSWORD — STEP 3
    // ============================================================
    public function resetPassword(Request $request)
    {
        $validator = validator($request->all(), [
            'user_id' => 'required|string',
            'new_password' => [
                'required',
                'string',
                'min:8',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'regex:/[^A-Za-z0-9]/',
            ],
            'password_confirmation' => 'required|same:new_password',
            'reset_token' => 'required|string',
        ], [
            'new_password.min' => 'Password must be at least 8 characters.',
            'new_password.regex' => 'Password must include uppercase, lowercase, number, and special character.',
            'password_confirmation.same' => 'Passwords do not match.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $identifier = $request->user_id;

        $user = User::where('username', $identifier)
            ->orWhereHas('person', fn($q) => $q->where('email', $identifier))
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
                'errors' => ['user_id' => ['User not found.']],
            ], 404);
        }

        $cachedToken = Cache::get('password-reset-token:' . $user->user_id);

        if (!$cachedToken || $cachedToken !== $request->reset_token) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired password reset token. Please start over.',
                'errors' => ['reset_token' => ['Invalid or expired token.']],
            ], 422);
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        Cache::forget('password-reset-otp:' . $user->user_id);
        Cache::forget('password-reset-token:' . $user->user_id);

        if (method_exists($user, 'clearFailedLogins')) {
            $user->clearFailedLogins();
        }

        // Revoke all existing sessions after password reset
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. You can now log in with your new password.',
        ], 200);
    }

    // ============================================================
    // USER, PROFILE, CHANGE PASSWORD, LOGOUT
    // ============================================================
    public function user(Request $request)
    {
        $user = $request->user()->load(['person', 'roles', 'customer', 'employee']);
        return $this->ok(['user' => $this->payload($user)]);
    }

    public function profile(Request $request)
    {
        return $this->user($request);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user()->load('person');
        $person = $user->person;

        if (!$person) return $this->fail('Person record not found', 404);

        $validated = $request->validate([
            'full_name' => ['nullable', 'string', 'max:160'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120', 'unique:persons,email,' . $person->person_id . ',person_id'],
            'phone' => ['nullable', 'string', 'max:30'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'location' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'address_line_1' => ['nullable', 'string'],
            'address_line_2' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:80'],
            'province' => ['nullable', 'string', 'max:80'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:80'],
            'gender' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date'],
        ]);

        $personData = [];

        if ($request->filled('full_name')) {
            $parts = explode(' ', trim($request->full_name), 2);
            $personData['first_name'] = $parts[0] ?? $person->first_name;
            $personData['last_name'] = $parts[1] ?? $person->last_name;
        }
        if ($request->filled('first_name')) $personData['first_name'] = $request->first_name;
        if ($request->filled('last_name')) $personData['last_name'] = $request->last_name;
        if ($request->filled('email')) $personData['email'] = strtolower(trim($request->email));
        if ($request->filled('phone')) $personData['phone'] = $request->phone;
        if ($request->filled('phone_number')) $personData['phone'] = $request->phone_number;
        if ($request->filled('address')) $personData['address_line_1'] = $request->address;
        if ($request->filled('address_line_1')) $personData['address_line_1'] = $request->address_line_1;
        if ($request->filled('city')) $personData['city'] = $request->city;
        if ($request->filled('province')) $personData['province'] = $request->province;

        if (!empty($personData)) $person->update($personData);

        // Persist bio and location via settings
        if (array_key_exists('bio', $validated)) {
            Setting::setValue('user_profile', 'user_' . $user->user_id . '_bio', $validated['bio'] ?? '', 'string');
        }
        if (array_key_exists('location', $validated)) {
            Setting::setValue('user_profile', 'user_' . $user->user_id . '_location', $validated['location'] ?? '', 'string');
        }

        // NOTE: Username is intentionally NOT synced with email.
        // Username and email are independent identities. Overwriting
        // username whenever the user changes email caused the
        // "User ID changes when I change my email" bug.

        // FIXED: Reload relationships so the response includes fresh person
        // and employee data (profile photo, department, position, etc.)
        $user->refresh()->load(['person', 'roles', 'customer', 'employee.department', 'employee.position']);

        return $this->ok(['user' => $this->payload($user)]);
    }
    // ============================================================
    // ⭐ SELF-PROFILE UPDATE (for mobile app / employee self-service)
    // Only updates the caller's OWN person fields. Cannot touch
    // department, position, salary, status, or role.
    // ============================================================
    public function updateSelfProfile(Request $request)
    {
        $user = $request->user()->load(['person', 'employee']);

        if (! $user->person) {
            return $this->fail('Person record not found', 404);
        }

        $person = $user->person;

        // ⭐ Use Laravel's Rule::unique builder so the "except" clause is
        //    guaranteed to reference the right primary key regardless of
        //    whether Person uses `person_id` or `id` as its key.
        $personKeyName = $person->getKeyName();      // e.g. 'person_id'
        $personKeyValue = $person->getKey();         // e.g. 45

        $validated = $request->validate([
            'first_name'    => ['nullable', 'string', 'max:80'],
            'last_name'     => ['nullable', 'string', 'max:80'],
            'middle_name'   => ['nullable', 'string', 'max:80'],
            'suffix'        => ['nullable', 'string', 'max:20'],
            'email'         => [
                'nullable',
                'email',
                'max:120',
                \Illuminate\Validation\Rule::unique('persons', 'email')->ignore($personKeyValue, $personKeyName),
            ],
            'phone'         => ['nullable', 'string', 'max:30'],
            'address'       => ['nullable', 'string'],
            'address_line_1' => ['nullable', 'string'],
            'address_line_2' => ['nullable', 'string'],
            'city'          => ['nullable', 'string', 'max:80'],
            'province'      => ['nullable', 'string', 'max:80'],
            'postal_code'   => ['nullable', 'string', 'max:20'],
            'country'       => ['nullable', 'string', 'max:80'],
            'gender'        => ['nullable', 'string', 'max:30'],
            'birth_date'    => ['nullable', 'date'],
        ]);

        $personData = [];

        if (array_key_exists('first_name', $validated))      $personData['first_name'] = $validated['first_name'];
        if (array_key_exists('last_name', $validated))       $personData['last_name'] = $validated['last_name'];
        if (array_key_exists('middle_name', $validated))     $personData['middle_name'] = $validated['middle_name'];
        if (array_key_exists('suffix', $validated))          $personData['suffix'] = $validated['suffix'];
        if (array_key_exists('email', $validated))           $personData['email'] = strtolower(trim((string) $validated['email']));
        if (array_key_exists('phone', $validated))           $personData['phone'] = $validated['phone'];
        if (array_key_exists('address', $validated))         $personData['address_line_1'] = $validated['address'];
        if (array_key_exists('address_line_1', $validated))  $personData['address_line_1'] = $validated['address_line_1'];
        if (array_key_exists('address_line_2', $validated))  $personData['address_line_2'] = $validated['address_line_2'];
        if (array_key_exists('city', $validated))            $personData['city'] = $validated['city'];
        if (array_key_exists('province', $validated))        $personData['province'] = $validated['province'];
        if (array_key_exists('postal_code', $validated))     $personData['postal_code'] = $validated['postal_code'];
        if (array_key_exists('country', $validated))         $personData['country'] = $validated['country'];
        if (array_key_exists('gender', $validated)) {
            $gender = $validated['gender'];
            $personData['gender'] = ($gender === 'prefer_not_to_say' || $gender === '') ? null : $gender;
        }
        if (array_key_exists('birth_date', $validated))      $personData['birth_date'] = $validated['birth_date'];
        if (! empty($personData)) {
            $person->update($personData);
        }

        // NOTE: Username is intentionally NOT synced with email.
        // Same reason as updateProfile() above.

        $user->refresh()->load(['person', 'roles', 'customer', 'employee']);

        return $this->ok([
            'user' => $this->payload($user),
            'employee' => $user->employee,
        ], 'Profile updated successfully');
    }

    public function updateProfilePhoto(Request $request)
    {
        $user = $request->user();
        $person = $user->person;
        if (!$person) return $this->fail('Person record not found', 404);

        // FIXED: Removed 'max:2048' size limit — now accepts any image size
        $request->validate(['profile_photo' => 'required|image']);

        // Delete the old photo first to avoid orphaned files
        if ($person->profile_photo) {
            $old = $person->profile_photo;
            if (!str_starts_with($old, 'http://') && !str_starts_with($old, 'https://')) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete(
                    ltrim(str_replace('/storage/', '', $old), '/')
                );
            }
        }

        $path = $request->file('profile_photo')->store('profile-photos', 'public');
        $person->update(['profile_photo' => $path]);

        $user->refresh()->load(['person', 'roles', 'customer', 'employee.department', 'employee.position']);

        return $this->ok([
            'user' => $this->payload($user),
        ], 'Profile photo updated successfully');
    }

    public function changePassword(Request $request)
    {
        $validator = validator($request->all(), [
            'current_password' => 'required|string',
            'password' => [
                'required',
                'string',
                'min:8',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'regex:/[^A-Za-z0-9]/',
                'confirmed',
            ],
            'password_confirmation' => 'required|same:password',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return $this->fail('Current password is incorrect', 422);
        }

        $user->update(['password' => Hash::make($request->password)]);

        // Revoke all sessions except the current one
        $currentTokenId = $user->currentAccessToken()?->id;
        if ($currentTokenId) {
            $user->tokens()->where('id', '!=', $currentTokenId)->delete();
        }

        return $this->ok(null, 'Password changed successfully');
    }

    public function removeProfilePhoto(Request $request)
    {
        $user = $request->user();
        $person = $user->person;

        if ($person && $person->profile_photo) {
            if (file_exists(public_path('storage/' . $person->profile_photo))) {
                unlink(public_path('storage/' . $person->profile_photo));
            }
            $person->update(['profile_photo' => null]);
        }

        return $this->ok(null, 'Profile photo removed');
    }

    public function logout(Request $request)
    {
        if ($request->user()) {
            AuditLog::log('user_logout', 'auth', $request->user()->user_id, null, [
                'email' => $request->user()->person?->email ?? $request->user()->username,
            ]);
        }
        $request->user()->currentAccessToken()?->delete();
        return $this->ok(null, 'Logged out successfully');
    }

    // ============================================================
    // EMAIL OTP
    // ============================================================
    public function requestRegistrationOtp(Request $request)
    {
        $validator = validator($request->all(), [
            'email' => 'required|email|max:120|unique:persons,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = strtolower(trim($request->email));
        $otp = (string) random_int(100000, 999999);
        $cacheKey = 'registration-otp:' . sha1($email);

        Cache::put($cacheKey, [
            'otp' => Hash::make($otp),
            'attempts' => 0,
            'verified' => false,
            'email' => $email,
        ], now()->addMinutes(10));

        try {
            $this->sendOtpEmail($email, $otp, 'Dear Ba\'bs Catering Registration OTP');
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send OTP email. Please try again later.',
                'errors' => ['email' => ['Email sending failed.']],
            ], 500);
        }

        $payload = ['email' => $email, 'expires_in_minutes' => 10];
        if (app()->environment(['local', 'development', 'testing'])) {
            $payload['debug_otp'] = $otp;
        }

        return $this->ok($payload, 'Registration OTP sent to email');
    }

    public function verifyRegistrationOtp(Request $request)
    {
        $validator = validator($request->all(), [
            'email' => 'required|email|max:120',
            'otp_code' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = strtolower(trim($request->email));
        $cacheKey = 'registration-otp:' . sha1($email);
        $record = Cache::get($cacheKey);

        if (!$record) return $this->fail('Invalid or expired OTP', 422);

        $attempts = (int) ($record['attempts'] ?? 0) + 1;
        if ($attempts > 5) {
            Cache::forget($cacheKey);
            return $this->fail('Too many OTP attempts. Please request a new code.', 429);
        }

        if (!Hash::check($request->otp_code, $record['otp'] ?? '')) {
            $record['attempts'] = $attempts;
            Cache::put($cacheKey, $record, now()->addMinutes(10));
            return $this->fail('Invalid or expired OTP', 422);
        }

        $record['verified'] = true;
        $record['verified_at'] = now()->toDateTimeString();
        Cache::put($cacheKey, $record, now()->addMinutes(20));

        return $this->ok(['email' => $email, 'verified' => true], 'Email OTP verified');
    }

    public function registerCustomerWithOtp(Request $request)
    {
        $validator = validator($request->all(), [
            'first_name' => 'required|string|max:80',
            'last_name' => 'required|string|max:80',
            'email' => 'required|email|max:120|unique:persons,email',
            'password' => [
                'required',
                'string',
                'min:8',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'regex:/[^A-Za-z0-9]/',
                'confirmed',
            ],
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string',
            'otp_code' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = strtolower(trim($request->email));
        $cacheKey = 'registration-otp:' . sha1($email);
        $record = Cache::get($cacheKey);

        if (!$record || !Hash::check($request->otp_code, $record['otp'] ?? '')) {
            return $this->fail('Invalid or expired OTP', 422);
        }

        return DB::transaction(function () use ($request, $cacheKey) {
            $person = Person::create([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => strtolower(trim($request->email)),
                'phone' => $request->phone ?? null,
                'address_line_1' => $request->address ?? null,
                'country' => 'Philippines',
            ]);

            $user = User::create([
                'person_id' => $person->person_id,
                'username' => strtolower(trim($request->email)),
                'password' => Hash::make($request->password),
                'email_verified_at' => now(),
                'is_active' => true,
            ]);

            $role = Role::where('slug', 'customer')->first();
            if ($role) $user->roles()->sync([$role->role_id]);

            $customer = Customer::create([
                'person_id' => $person->person_id,
                'user_id' => $user->user_id,
                'customer_code' => 'CUS-' . str_pad($user->user_id, 5, '0', STR_PAD_LEFT),
                'is_active' => true,
            ]);

            Cache::forget($cacheKey);

            $token = $user->createToken('auth_token')->plainTextToken;

            return $this->ok([
                'token' => $token,
                'user' => $this->payload($user->load(['person', 'roles', 'customer'])),
                'customer' => $customer,
                'role' => 'customer',
            ], 'Customer registered and email verified successfully');
        });
    }

    public function sendEmailOtp(Request $request)
    {
        $user = $request->user()?->loadMissing('person');
        if (!$user) return $this->fail('Unauthenticated', 401);

        $email = strtolower(trim($request->input('email') ?: ($user->person?->email ?? $user->username)));
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('A valid email address is required', 422);
        }

        $otp = (string) random_int(100000, 999999);
        $cacheKey = 'email-otp:' . sha1($user->user_id . '|' . $email);

        Cache::put($cacheKey, [
            'otp' => Hash::make($otp),
            'attempts' => 0,
            'email' => $email,
            'user_id' => $user->user_id,
        ], now()->addMinutes(10));

        try {
            $this->sendOtpEmail($email, $otp, 'Dear Ba\'bs Catering Email Verification OTP');
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send OTP email. Please try again later.',
                'errors' => ['email' => ['Email sending failed.']],
            ], 500);
        }

        $payload = ['email' => $email, 'expires_in_minutes' => 10];
        if (app()->environment(['local', 'development', 'testing'])) {
            $payload['debug_otp'] = $otp;
        }

        return $this->ok($payload, 'Email OTP sent');
    }

    public function verifyEmailOtp(Request $request)
    {
        $validator = validator($request->all(), [
            'email' => 'nullable|email|max:120',
            'otp_code' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user()?->loadMissing('person');
        if (!$user) return $this->fail('Unauthenticated', 401);

        $email = strtolower(trim($request->input('email') ?: ($user->person?->email ?? $user->username)));
        $cacheKey = 'email-otp:' . sha1($user->user_id . '|' . $email);
        $record = Cache::get($cacheKey);

        if (!$record) return $this->fail('Invalid or expired OTP', 422);

        $attempts = (int) ($record['attempts'] ?? 0) + 1;
        if ($attempts > 5) {
            Cache::forget($cacheKey);
            return $this->fail('Too many OTP attempts. Please request a new code.', 429);
        }

        if (!Hash::check($request->otp_code, $record['otp'] ?? '')) {
            $record['attempts'] = $attempts;
            Cache::put($cacheKey, $record, now()->addMinutes(10));
            return $this->fail('Invalid or expired OTP', 422);
        }

        $user->forceFill(['email_verified_at' => now()])->save();
        Cache::forget($cacheKey);

        return $this->ok([
            'email' => $email,
            'verified' => true,
            'user' => $this->payload($user->fresh(['person', 'roles', 'customer'])),
        ], 'Email OTP verified');
    }

    // ============================================================
    // PRIVATE HELPERS
    // ============================================================
    private function sendOtpEmail(string $email, string $otp, string $subject = 'Your OTP Code'): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email format: ' . $email);
        }

        $name = 'User';
        $user = User::whereHas('person', fn($q) => $q->where('email', $email))->first();
        if ($user && $user->person) $name = $user->person->first_name ?: 'User';

        try {
            Mail::to($email)->send(new OTPMail($otp, $name, $subject));
            Log::info('OTP email sent successfully to: ' . $email, ['subject' => $subject]);
        } catch (\Throwable $e) {
            Log::error('Failed to send OTP email to ' . $email . ': ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            try {
                Mail::raw(
                    "Your OTP code is {$otp}. This code expires in 10 minutes. If you did not request this code, please ignore this email.",
                    function ($message) use ($email, $subject) {
                        $message->to($email)->subject($subject);
                    }
                );
                Log::info('OTP email sent via fallback raw method to: ' . $email);
            } catch (\Throwable $fallbackError) {
                Log::error('Fallback OTP email also failed: ' . $fallbackError->getMessage());
                throw $fallbackError;
            }
        }
    }

    private function verifyOrSendLoginOtp(User $user, string $otpCode)
    {
        $cacheKey = 'login-otp:' . $user->user_id;

        if ($otpCode !== '') {
            $cachedOtp = Cache::get($cacheKey);
            if (!$cachedOtp || !Hash::check($otpCode, $cachedOtp)) {
                return $this->fail('Invalid or expired login OTP', 422);
            }
            Cache::forget($cacheKey);
            return true;
        }

        $otp = (string) random_int(100000, 999999);
        Cache::put($cacheKey, Hash::make($otp), now()->addMinutes(10));

        $email = $user->person?->email ?? $user->username;
        $this->sendOtpEmail($email, $otp, 'Dear Ba\'bs Catering Login OTP');

        $payload = [
            'requires_otp' => true,
            'user_id' => $user->username,
            'email' => $email,
            'expires_in_minutes' => 10,
        ];
        if (app()->environment(['local', 'development', 'testing'])) {
            $payload['debug_otp'] = $otp;
        }

        return response()->json([
            'success' => true,
            'message' => 'Login OTP sent to your registered email.',
            'data' => $payload,
        ], 202);
    }

    private function primaryRole(User $user): string
    {
        $slugs = $user->roles->pluck('slug')->map(fn($slug) => strtolower((string) $slug))->all();

        if (array_intersect($slugs, ['super-admin', 'super_admin', 'superadmin'])) return 'super-admin';
        if (array_intersect($slugs, ['admin', 'administrator', 'owner'])) return 'admin';
        if (in_array('cashier', $slugs, true) || in_array('finance-staff', $slugs, true)) return 'cashier';
        if (in_array('head-chef', $slugs, true) || in_array('head_chef', $slugs, true)) return 'head-chef';
        if (in_array('staff-manager', $slugs, true) || in_array('staff_manager', $slugs, true)) return 'staff-manager';
        if (in_array('inventory-manager', $slugs, true) || in_array('inventory_manager', $slugs, true)) return 'inventory-manager';

        $employee = $user->employee;
        if ($employee) {
            $employee->loadMissing('position');
            $positionTitle = strtolower((string) ($employee->position?->title ?? $employee->position?->name ?? ''));
            if (str_contains($positionTitle, 'cashier')) return 'cashier';
            return 'employee';
        }

        return 'customer';
    }

    private function recordLogin(User $user, Request $request): void
    {
        try {
            AuditLog::log('user_login', 'auth', $user->user_id, null, [
                'email' => $user->person?->email ?? $user->username,
                'ip_address' => $request->ip(),
            ]);
            Cache::forget('failed_login_' . md5(($user->person?->email ?? $user->username) . '|' . $request->ip()));
        } catch (\Throwable $e) {
            // Silent
        }
    }

    private function recordFailedLogin(string $identifier, Request $request): void
    {
        try {
            AuditLog::log('failed_login_attempt', 'auth', null, null, [
                'identifier' => $identifier,
                'ip_address' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            // Silent
        }
    }

    private function payload(User $user): array
    {
        $person = $user->person;

        $customerId = null;
        if ($user->customer) {
            $id = (int) $user->customer->customer_id;
            $customerId = str_pad($id, 4, '0', STR_PAD_LEFT);
        }

        $primaryRole = $this->primaryRole($user);
        $bio = Setting::getValue('user_profile', 'user_' . $user->user_id . '_bio', '');
        $location = Setting::getValue('user_profile', 'user_' . $user->user_id . '_location', '');

        // FIXED: Properly resolve profile photo URL
        $profilePhoto = $person->profile_photo ?? null;
        $profilePhotoUrl = null;

        if ($profilePhoto) {
            if (
                str_starts_with($profilePhoto, 'http://') ||
                str_starts_with($profilePhoto, 'https://') ||
                str_starts_with($profilePhoto, '/')
            ) {
                $profilePhotoUrl = $profilePhoto;
            } else {
                $profilePhotoUrl = url(\Illuminate\Support\Facades\Storage::disk('public')->url($profilePhoto));
            }
        }

        // FIXED: Include employee data so the user account reflects employee profile
        $employee = $user->employee;

        return [
            'id' => $user->user_id,
            'user_id' => $user->username,
            'username' => $user->username,
            'internal_user_id' => $user->user_id,
            'full_name' => trim(($person->first_name ?? '') . ' ' . ($person->last_name ?? '')),
            'first_name' => $person->first_name ?? null,
            'last_name' => $person->last_name ?? null,
            'middle_name' => $person->middle_name ?? null,
            'suffix' => $person->suffix ?? null,
            'email' => $person->email ?? null,
            'phone_number' => $person->phone ?? null,
            'phone' => $person->phone ?? null,
            'bio' => $bio,
            'location' => $location,
            'country_code' => '+63',
            'role' => $primaryRole,
            'primary_role' => $primaryRole,
            'roles' => $user->roles->pluck('slug')->toArray(),
            'is_verified' => (bool) $user->email_verified_at,
            'is_active' => (bool) $user->is_active,
            'is_banned' => (bool) ($user->is_banned ?? false),
            'profile_photo' => $profilePhoto,
            'profile_photo_url' => $profilePhotoUrl,
            'customer_id' => $customerId,
            'employee_id' => $employee?->employee_id,
            'employee_code' => $employee?->employee_code,
            'department' => $employee?->department?->name,
            'position' => $employee?->position?->title ?? $employee?->position?->name,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}
