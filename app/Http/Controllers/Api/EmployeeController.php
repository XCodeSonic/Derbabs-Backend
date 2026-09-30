<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\EmployeeRequest;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Person;
use App\Models\Position;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EmployeeController extends Controller
{
    /**
     * Generate sequential employee code (EMP-0013 format)
     */
    private function generateEmployeeCode(): string
    {
        return $this->generateSequentialNumber('EMP-', Employee::class, 'employee_code');
    }

    /**
     * Generic sequential number generator
     */
    private function generateSequentialNumber(string $prefix, string $modelClass, string $column, int $padding = 4): string
    {
        try {
            if (!class_exists($modelClass)) {
                throw new \Exception("Model class {$modelClass} not found");
            }

            $instance = new $modelClass();
            $keyName = $instance->getKeyName();

            $lastRecord = $modelClass::withTrashed()
                ->where($column, 'LIKE', $prefix . '%')
                ->orderBy($keyName, 'desc')
                ->first();

            if ($lastRecord && isset($lastRecord->$column)) {
                $lastNumber = intval(substr($lastRecord->$column, strlen($prefix)));
                $newNumber = str_pad($lastNumber + 1, $padding, '0', STR_PAD_LEFT);
            } else {
                $newNumber = str_repeat('0', $padding - 1) . '1';
            }

            return $prefix . $newNumber;
        } catch (\Exception $e) {
            Log::warning("Failed to generate sequential number for {$prefix}: " . $e->getMessage());
            return $prefix . str_pad((string) (time() % 10000), $padding, '0', STR_PAD_LEFT);
        }
    }

    public function index(Request $request)
    {
        $query = Employee::with(['person', 'department', 'position.salaryGrade']);

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('employee_code', 'like', "%{$search}%")
                    ->orWhereHas('person', function ($person) use ($search) {
                        $person->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('department_id') && $request->input('department_id') !== 'all') {
            $query->where('department_id', $request->input('department_id'));
        }

        if ($request->filled('status') && $request->input('status') !== 'all' && $request->input('status') !== 'bookmarked') {
            $query->where('status', $this->normalizeStatus($request->input('status')));
        }

        $bookmarkedIds = $this->bookmarkedEmployeeIds();
        if ($request->boolean('bookmarked') || $request->input('status') === 'bookmarked' || $request->input('filter') === 'bookmarked') {
            $bookmarkedIds->isEmpty()
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('employee_id', $bookmarkedIds->all());
        }

        if ($request->boolean('all')) {
            $employees = $query->orderBy('employee_code')->get();
            $this->applyBookmarkFlags($employees, $bookmarkedIds);
            return $this->ok($employees);
        }

        $employees = $query->latest('employee_id')->paginate($request->integer('per_page', 10));
        $collection = $employees->getCollection();
        $this->applyBookmarkFlags($collection, $bookmarkedIds);
        $employees->setCollection($collection);

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $employees->items(),
            'pagination' => [
                'current_page' => $employees->currentPage(),
                'last_page' => $employees->lastPage(),
                'per_page' => $employees->perPage(),
                'total' => $employees->total(),
            ],
        ]);
    }

    public function active(Request $request)
    {
        $request->merge(['status' => 'active', 'all' => true]);
        return $this->index($request);
    }

    public function archived(Request $request)
    {
        $query = Employee::onlyTrashed()->with(['person', 'department', 'position.salaryGrade']);
        return $this->ok($query->latest('deleted_at')->paginate($request->integer('per_page', 20)));
    }

    public function store(EmployeeRequest $request)
    {
        $profilePhotoPath = null;

        try {
            $profilePhotoPath = $this->storeProfilePhoto($request);

            return DB::transaction(function () use ($request, $profilePhotoPath) {
                $person = Person::create($this->personPayload($request, null, $profilePhotoPath, true));

                $user = User::create([
                    'person_id' => $person->person_id,
                    'username' => $request->input('email'),
                    'password' => Hash::make($request->input('password', 'password123')),
                    'is_active' => true,
                ]);

                $this->assignEmployeeRole($user);

                $position = Position::with('salaryGrade')->findOrFail($request->input('position_id'));
                $hourlyRate = $request->filled('hourly_rate_override')
                    ? (float) $request->input('hourly_rate_override')
                    : (float) ($position->salaryGrade?->default_hourly_rate ?? 0);

                $employee = Employee::create([
                    'person_id' => $person->person_id,
                    'user_id' => $user->user_id,
                    'department_id' => $request->input('department_id'),
                    'position_id' => $position->position_id,
                    'employee_code' => $this->generateEmployeeCode(),
                    'status' => $this->normalizeStatus($request->input('status', 'active')),
                    'hire_date' => $request->input('hire_date'),
                    'hourly_rate' => $hourlyRate,
                    'sss_number' => $request->input('sss_number', $request->input('sss')),
                    'philhealth_number' => $request->input('philhealth_number', $request->input('philhealth')),
                    'pagibig_number' => $request->input('pagibig_number', $request->input('pagibig')),
                    'tin_number' => $request->input('tin_number', $request->input('tin')),
                    'notes' => $request->input('notes'),
                ]);

                return $this->ok(
                    $employee->load(['person', 'department', 'position.salaryGrade']),
                    'Employee created'
                );
            });
        } catch (Throwable $exception) {
            $this->deleteProfilePhoto($profilePhotoPath);
            return $this->failureResponse('Unable to create the employee record.', $exception);
        }
    }

    public function show(Employee $employee)
    {
        $employee->load([
            'person',
            'department',
            'position.salaryGrade',
            'attendanceLogs',
            'payrolls',
        ]);
        $this->applyBookmarkFlags(collect([$employee]));

        return $this->ok($employee);
    }

    public function update(EmployeeRequest $request, Employee $employee)
    {
        $oldPhotoPath = null;
        $newPhotoPath = null;
        $removePhoto = false;
        $storedPhotoPath = null;

        try {
            $employee->loadMissing(['person', 'user', 'position.salaryGrade']);

            if (! $employee->person) {
                throw new RuntimeException('The selected employee is missing its related person record.');
            }

            $oldPhotoPath = $employee->person->profile_photo;
            $newPhotoPath = $this->storeProfilePhoto($request);
            $removePhoto = $request->boolean('remove_profile_photo');
            $storedPhotoPath = $removePhoto ? null : ($newPhotoPath ?? $oldPhotoPath);

            $response = DB::transaction(function () use (
                $request,
                $employee,
                $storedPhotoPath,
                $newPhotoPath,
                $removePhoto
            ) {
                $employee->person->update($this->personPayload(
                    $request,
                    $employee->person,
                    $storedPhotoPath,
                    $newPhotoPath !== null || $removePhoto
                ));

                if ($employee->user && $request->filled('email')) {
                    $employee->user->update(['username' => $request->input('email')]);
                }

                $employeeData = collect($request->only([
                    'department_id',
                    'position_id',
                    'hire_date',
                    'regularization_date',
                    'termination_date',
                    'hourly_rate',
                    'sss_number',
                    'philhealth_number',
                    'pagibig_number',
                    'tin_number',
                    'notes',
                ]))->filter(static fn($value) => $value !== null)->toArray();

                foreach (
                    [
                        'sss_number' => 'sss',
                        'philhealth_number' => 'philhealth',
                        'pagibig_number' => 'pagibig',
                        'tin_number' => 'tin',
                    ] as $databaseField => $alias
                ) {
                    if (! array_key_exists($databaseField, $employeeData) && $request->has($alias)) {
                        $employeeData[$databaseField] = $request->input($alias);
                    }
                }

                if ($request->filled('hourly_rate_override')) {
                    $employeeData['hourly_rate'] = (float) $request->input('hourly_rate_override');
                }

                if ($request->filled('status')) {
                    $employeeData['status'] = $this->normalizeStatus($request->input('status'));
                }

                $employee->update($employeeData);
                // ⭐ #2 — Block the linked user account whenever the employee
                //    status becomes inactive or terminated. This prevents them
                //    from logging into Attendance Tracking.
                if ($employee->user && isset($employeeData['status'])) {
                    $isActive = in_array($employeeData['status'], ['active', 'on_leave'], true);
                    $employee->user->update(['is_active' => $isActive]);

                    // Force logout any existing Sanctum sessions so the mobile
                    // app's cached token is invalidated immediately.
                    if (! $isActive) {
                        try {
                            $employee->user->tokens()->delete();
                        } catch (Throwable $e) {
                            Log::warning('Failed to revoke tokens on employee deactivation', [
                                'user_id' => $employee->user->user_id,
                                'exception' => $e->getMessage(),
                            ]);
                        }
                    }
                }

                // FIXED: Keep the linked user account's person data in sync
                //        with the employee's person data. This makes the user
                //        profile reflect the employee profile automatically.
                if ($employee->user) {
                    try {
                        $employee->user->update([
                            'username' => $employee->person->email ?? $employee->user->username,
                        ]);
                    } catch (Throwable $e) {
                        Log::warning('Failed to sync user username with employee email', [
                            'user_id' => $employee->user->user_id,
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }

                return $this->ok(
                    $employee->fresh(['person', 'department', 'position.salaryGrade']),
                    'Employee updated'
                );
            });
        } catch (Throwable $exception) {
            if ($newPhotoPath) {
                $this->deleteProfilePhoto($newPhotoPath);
            }
            return $this->failureResponse('Unable to update the employee record.', $exception);
        }

        if (($newPhotoPath || $removePhoto) && $oldPhotoPath && $oldPhotoPath !== $storedPhotoPath) {
            $this->deleteProfilePhoto($oldPhotoPath);
        }

        return $response;
    }
    public function destroy(Employee $employee)
    {
        DB::transaction(function () use ($employee) {
            // ⭐ #2 — Block the linked user account when archived.
            $employee->loadMissing('user');
            if ($employee->user) {
                $employee->user->update(['is_active' => false]);
                try {
                    $employee->user->tokens()->delete();
                } catch (Throwable $e) {
                    Log::warning('Failed to revoke tokens on archive', [
                        'user_id' => $employee->user->user_id,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }

            $employee->delete();
        });

        return $this->ok(null, 'Employee archived');
    }
    public function restore($id)
    {
        $employee = Employee::withTrashed()->findOrFail($id);

        DB::transaction(function () use ($employee) {
            $employee->restore();
            $employee->loadMissing('user');

            // ⭐ #2 — Reactivate the linked user account.
            if ($employee->user && $employee->status === 'active') {
                $employee->user->update(['is_active' => true]);
            }
        });

        return $this->ok($employee->fresh(['person', 'department', 'position.salaryGrade']), 'Employee restored');
    }

    public function forcePasswordReset(Request $request, Employee $employee)
    {
        $employee->loadMissing('user');
        if (! $employee->user) {
            return $this->error('The employee does not have a linked user account.', 422);
        }

        $data = $request->validate([
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
        ]);
        $temporaryPassword = $data['password'] ?? Str::password(14);
        $employee->user->update(['password' => Hash::make($temporaryPassword)]);

        return $this->ok([
            'employee_id' => $employee->employee_id,
            'temporary_password' => array_key_exists('password', $data) ? null : $temporaryPassword,
        ], 'Employee password reset successfully');
    }

    public function block(Employee $employee)
    {
        return DB::transaction(function () use ($employee) {
            $employee->loadMissing('user');
            $employee->update(['status' => 'inactive']);
            $employee->user?->update(['is_active' => false]);
            return $this->ok($employee->fresh(['person', 'user']), 'Employee account blocked');
        });
    }

    public function unblock(Employee $employee)
    {
        return DB::transaction(function () use ($employee) {
            $employee->loadMissing('user');
            $employee->update(['status' => 'active']);
            $employee->user?->update(['is_active' => true]);
            return $this->ok($employee->fresh(['person', 'user']), 'Employee account unblocked');
        });
    }

    public function bulkImport(Request $request)
    {
        $data = $request->validate([
            'employees' => ['required', 'array', 'min:1', 'max:500'],
            'employees.*.first_name' => ['required', 'string', 'max:80'],
            'employees.*.last_name' => ['required', 'string', 'max:80'],
            'employees.*.email' => ['required', 'email', 'distinct', 'unique:persons,email'],
            'employees.*.phone' => ['required', 'string', 'max:30'],
            'employees.*.department_id' => ['required', 'exists:departments,department_id'],
            'employees.*.position_id' => ['required', 'exists:positions,position_id'],
            'employees.*.hire_date' => ['required', 'date'],
            'employees.*.status' => ['nullable', 'string'],
            'employees.*.password' => ['nullable', 'string', 'min:8', 'max:100'],
        ]);

        $created = DB::transaction(function () use ($data) {
            return collect($data['employees'])->map(function (array $row) {
                $person = Person::create([
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'email' => $row['email'],
                    'phone' => $row['phone'],
                ]);
                $user = User::create([
                    'person_id' => $person->person_id,
                    'username' => $row['email'],
                    'password' => Hash::make($row['password'] ?? 'password123'),
                    'is_active' => true,
                ]);
                $this->assignEmployeeRole($user);
                $position = Position::with('salaryGrade')->findOrFail($row['position_id']);

                return Employee::create([
                    'person_id' => $person->person_id,
                    'user_id' => $user->user_id,
                    'department_id' => $row['department_id'],
                    'position_id' => $position->position_id,
                    'employee_code' => $this->generateEmployeeCode(),
                    'status' => $this->normalizeStatus($row['status'] ?? 'active'),
                    'hire_date' => $row['hire_date'],
                    'hourly_rate' => (float) ($position->salaryGrade?->default_hourly_rate ?? 0),
                ])->load(['person', 'department', 'position.salaryGrade']);
            })->values();
        });

        return $this->ok($created, $created->count() . ' employees imported successfully');
    }

    public function stats()
    {
        return $this->ok([
            'total' => Employee::count(),
            'active' => Employee::where('status', 'active')->count(),
            'onleave' => Employee::where('status', 'on_leave')->count(),
            'inactive' => Employee::where('status', 'inactive')->count(),
            'terminated' => Employee::where('status', 'terminated')->count(),
        ]);
    }

    public function bulkStatus(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:employees,employee_id',
            'status' => 'required|string',
        ]);

        $normalizedStatus = $this->normalizeStatus($data['status']);
        $isActive = in_array($normalizedStatus, ['active', 'on_leave'], true);

        DB::transaction(function () use ($data, $normalizedStatus, $isActive) {
            Employee::whereIn('employee_id', $data['ids'])->update([
                'status' => $normalizedStatus,
            ]);

            // ⭐ #2 — Sync the linked user accounts so they can / cannot log in.
            $employees = Employee::with('user')
                ->whereIn('employee_id', $data['ids'])
                ->get();

            foreach ($employees as $emp) {
                if (! $emp->user) {
                    continue;
                }

                $emp->user->update(['is_active' => $isActive]);

                // Force logout active sessions when deactivated.
                if (! $isActive) {
                    try {
                        $emp->user->tokens()->delete();
                    } catch (Throwable $e) {
                        Log::warning('Failed to revoke tokens during bulk status', [
                            'user_id' => $emp->user->user_id,
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            }
        });

        return $this->ok(null, 'Employee status updated');
    }

    /**
     * Eligible employees for payroll.
     *
     * FIX: Admins / HR / Payroll / Manager roles can process payroll at any time.
     * The "attendance saved as payroll ready" gate is bypassed for those roles.
     * Non-admin users still see the blocker.
     */
    public function eligibleForPayroll(Request $request, AttendanceService $attendanceService)
    {
        $data = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'department_id' => 'nullable',
        ]);

        $query = Employee::with(['person', 'department', 'position.salaryGrade'])
            ->where('status', 'active');

        if (! empty($data['department_id']) && $data['department_id'] !== 'all') {
            $query->where('department_id', $data['department_id']);
        }

        // FIX: Determine whether the current user can bypass the payroll-ready gate
        $user = auth()->user();
        $isAdmin = $user && method_exists($user, 'hasAnyRole')
            ? $user->hasAnyRole(['admin', 'super_admin', 'hr', 'manager', 'payroll'])
            : false;

        $employees = $query->get()->map(function (Employee $employee) use ($data, $attendanceService, $isAdmin) {
            $allAttendance = AttendanceLog::with(['schedule', 'overtimeRequest'])
                ->where('employee_id', $employee->employee_id)
                ->whereBetween('attendance_date', [$data['start_date'], $data['end_date']])
                ->orderBy('attendance_date')
                ->get()
                ->map(function (AttendanceLog $attendance) use ($attendanceService) {
                    return $attendance->time_in && $attendance->time_out
                        ? $attendanceService->recalculate($attendance)
                        : $attendance;
                });

            $approvedAttendance = $allAttendance->where('approval_status', 'approved');
            $regularHours = round((float) $approvedAttendance->sum('regular_hours'), 2);
            $overtimeHours = round((float) $approvedAttendance->where('overtime_approved', true)->sum('overtime_hours'), 2);
            $hourlyRate = (float) $employee->calculated_hourly_rate;

            $hasPayroll = Payroll::withTrashed()
                ->where('employee_id', $employee->employee_id)
                ->whereDate('cutoff_start', $data['start_date'])
                ->whereDate('cutoff_end', $data['end_date'])
                ->exists();

            $blockers = collect();
            $softNotes = collect();

            // Missing time-in / time-out is always a hard blocker
            $incomplete = $allAttendance->first(
                fn(AttendanceLog $row) => $row->status !== 'absent' && (! $row->time_in || ! $row->time_out)
            );
            if ($incomplete) {
                $blockers->push('Missing time-in/time-out on ' . $incomplete->attendance_date?->toDateString());
            }

            // Pending attendance decision is a hard blocker
            $pending = $allAttendance->first(fn(AttendanceLog $row) => $row->approval_status === 'pending');
            if ($pending) {
                $blockers->push('Attendance decision pending on ' . $pending->attendance_date?->toDateString());
            }

            // Unresolved overtime is a hard blocker
            $unresolvedOvertime = $allAttendance->first(function (AttendanceLog $row) {
                return (float) $row->overtime_hours > 0
                    && ! (bool) $row->overtime_approved
                    && ! in_array($row->overtimeRequest?->status, ['approved', 'rejected'], true);
            });
            if ($unresolvedOvertime) {
                $blockers->push('Overtime decision pending on ' . $unresolvedOvertime->attendance_date?->toDateString());
            }

            // No attendance
            if ($allAttendance->isEmpty()) {
                $blockers->push('No attendance records');
            }

            // Payroll-ready gate — bypass for admins
            $notFinalized = $allAttendance->first(fn(AttendanceLog $row) => ! $row->payroll_ready_at);
            if ($notFinalized) {
                if ($isAdmin) {
                    $softNotes->push('Attendance was not finalized for payroll; admin override applied.');
                } else {
                    $blockers->push('Attendance has not been saved as payroll ready');
                }
            }

            // Already processed
            if ($hasPayroll) {
                $blockers->push('Already processed');
            }

            $eligible = $blockers->isEmpty();

            $employee->attendance_count = $allAttendance->count();
            $employee->approved_attendance_count = $approvedAttendance->count();
            $employee->regular_hours = $regularHours;
            $employee->overtime_hours = $overtimeHours;
            $employee->total_hours = round($regularHours + $overtimeHours, 2);
            $employee->hourly_rate = $hourlyRate;
            $employee->estimated_gross_pay = round(
                ($regularHours * $hourlyRate) + ($overtimeHours * $hourlyRate * 1.25),
                2
            );
            $employee->has_attendance = $allAttendance->isNotEmpty();
            $employee->has_payroll = $hasPayroll;
            $employee->payroll_ready = $eligible;
            $employee->eligible = $eligible;
            $employee->eligibility_blockers = $blockers->values();
            $employee->soft_notes = $softNotes->values();
            $employee->eligibility_status = $eligible
                ? 'Eligible'
                : ($isAdmin && $blockers->count() === 1 && $softNotes->isNotEmpty() && ! $hasPayroll
                    ? 'Eligible (Admin Override)'
                    : $blockers->implode('; '));

            return $employee;
        });

        return $this->ok([
            'employees' => $employees,
            'summary' => [
                'total_employees' => $employees->count(),
                'eligible_count' => $employees->where('eligible', true)->count(),
                'has_payroll_count' => $employees->where('has_payroll', true)->count(),
                'needs_attention_count' => $employees->where('eligible', false)->where('has_payroll', false)->count(),
                'regular_hours' => round((float) $employees->sum('regular_hours'), 2),
                'overtime_hours' => round((float) $employees->sum('overtime_hours'), 2),
                'total_hours' => round((float) $employees->sum('total_hours'), 2),
                'estimated_total_pay' => round((float) $employees->sum('estimated_gross_pay'), 2),
            ],
        ]);
    }

    private function bookmarkedEmployeeIds()
    {
        $prefix = 'user_' . (auth()->id() ?? 0) . '_employee_';

        return Setting::where('group', 'employee_bookmarks')
            ->where('key', 'like', $prefix . '%')
            ->get()
            ->map(function (Setting $setting) use ($prefix) {
                return (int) str_replace($prefix, '', $setting->key);
            })
            ->filter()
            ->values();
    }

    private function applyBookmarkFlags($employees, $bookmarkedIds = null): void
    {
        $bookmarkedIds = $bookmarkedIds ?: $this->bookmarkedEmployeeIds();
        $bookmarkedLookup = array_flip($bookmarkedIds->all());

        foreach ($employees as $employee) {
            $isBookmarked = array_key_exists((int) $employee->employee_id, $bookmarkedLookup);
            $employee->setAttribute('is_bookmarked', $isBookmarked);
            $employee->setAttribute('bookmarked', $isBookmarked);
        }
    }

    private function normalizeStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'onleave', 'on-leave', 'on_leave' => 'on_leave',
            'inactive' => 'inactive',
            'terminated' => 'terminated',
            default => 'active',
        };
    }

    private function assignEmployeeRole(User $user): void
    {
        try {
            $role = Role::where('slug', 'employee')->first();

            if ($role && method_exists($user, 'roles')) {
                $user->roles()->syncWithoutDetaching([$role->role_id]);
            }
        } catch (Throwable $exception) {
            Log::warning('Employee profile was created, but the employee role could not be assigned.', [
                'user_id' => $user->user_id,
                'exception' => $exception,
            ]);
        }
    }

    private function storeProfilePhoto(Request $request): ?string
    {
        if (! $request->hasFile('profile_photo')) {
            return null;
        }

        $photo = $request->file('profile_photo');

        if (! $photo || ! $photo->isValid()) {
            throw new RuntimeException('The selected profile photo was not uploaded correctly.');
        }

        $disk = Storage::disk('public');
        $disk->makeDirectory('employee-profiles');
        $storedPath = $disk->putFile('employee-profiles', $photo);

        if (! $storedPath) {
            throw new RuntimeException('Laravel could not write the profile photo to the public storage disk.');
        }

        return $storedPath;
    }

    private function failureResponse(string $message, Throwable $exception)
    {
        $errorId = (string) Str::uuid();

        Log::error($message, [
            'error_id' => $errorId,
            'exception' => $exception,
        ]);

        $payload = [
            'success' => false,
            'message' => $message . ' Check storage/logs/laravel.log and search for error ID ' . $errorId . '.',
            'error_id' => $errorId,
        ];

        if ((bool) config('app.debug')) {
            $payload['debug'] = $exception->getMessage();
        }

        return response()->json($payload, 500);
    }

    private function deleteProfilePhoto(?string $path): void
    {
        if (! $path || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return;
        }

        Storage::disk('public')->delete(ltrim(str_replace('/storage/', '', $path), '/'));
    }

    private function personPayload(
        Request $request,
        ?Person $person = null,
        ?string $profilePhotoPath = null,
        bool $replaceProfilePhoto = false
    ): array {
        $gender = $request->input('gender', $person?->gender);
        if ($gender === 'prefer_not_to_say' || $gender === '') {
            $gender = null;
        }

        return [
            'first_name' => $request->input('first_name', $person?->first_name),
            'last_name' => $request->input('last_name', $person?->last_name),
            'middle_name' => $request->input('middle_name', $person?->middle_name),
            'suffix' => $request->input('suffix', $person?->suffix),
            'email' => $request->input('email', $person?->email),
            'phone' => $request->input('phone', $person?->phone),
            'alternate_phone' => $request->input('alternate_phone', $person?->alternate_phone),
            'birth_date' => $request->input('birth_date', $person?->birth_date),
            'gender' => $gender,
            'address_line_1' => $request->input('address_line_1', $request->input('address', $person?->address_line_1)),
            'address_line_2' => $request->input('address_line_2', $person?->address_line_2),
            'city' => $request->input('city', $person?->city),
            'province' => $request->input('province', $request->input('state', $person?->province)),
            'postal_code' => $request->input('postal_code', $person?->postal_code),
            'country' => $request->input('country', $person?->country ?? 'Philippines'),
            'profile_photo' => $replaceProfilePhoto ? $profilePhotoPath : $person?->profile_photo,
        ];
    }
}
