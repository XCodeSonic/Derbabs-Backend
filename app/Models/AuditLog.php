<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\AuditLogCatalog;

class AuditLog extends Model
{
    protected $table = 'audit_logs';
    protected $primaryKey = 'audit_id';
    protected $guarded = [];

    protected $casts = [
        'old_values' => 'json',
        'new_values' => 'json',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    // ==================== ACTION TYPES ====================
    const ACTION_LOGIN = 'login';
    const ACTION_LOGOUT = 'logout';
    const ACTION_CREATE = 'create';
    const ACTION_UPDATE = 'update';
    const ACTION_DELETE = 'delete';
    const ACTION_APPROVE = 'approve';
    const ACTION_REJECT = 'reject';
    const ACTION_CANCEL = 'cancel';
    const ACTION_COMPLETE = 'complete';
    const ACTION_PAYMENT = 'payment';
    const ACTION_CONFIRM = 'confirm';
    const ACTION_SECURITY = 'security';
    const ACTION_CONFIG = 'config';
    const ACTION_INVENTORY = 'inventory';
    const ACTION_PAYROLL = 'payroll';
    const ACTION_ATTENDANCE = 'attendance';
    const ACTION_EMPLOYEE = 'employee';
    const ACTION_BOOKING = 'booking';
    const ACTION_PRICING = 'pricing';
    const ACTION_DELIVERY = 'delivery';
    const ACTION_PERMISSION = 'permission';
    const ACTION_PASSWORD = 'password';
    const ACTION_FAILED_LOGIN = 'failed_login';
    const ACTION_ACCOUNT_LOCK = 'account_lock';

    // ==================== MODULE NAMES ====================
    const MODULE_AUTH = 'auth';
    const MODULE_BOOKINGS = 'bookings';
    const MODULE_EMPLOYEES = 'employees';
    const MODULE_PAYROLL = 'payroll';
    const MODULE_INVENTORY = 'inventory';
    const MODULE_PAYMENTS = 'payments';
    const MODULE_ORDERS = 'orders';
    const MODULE_USERS = 'users';
    const MODULE_ROLES = 'roles';
    const MODULE_SETTINGS = 'settings';
    const MODULE_ATTENDANCE = 'attendance';
    const MODULE_SCHEDULES = 'schedules';
    const MODULE_MENU = 'menu';
    const MODULE_PACKAGES = 'packages';
    const MODULE_PROMOTIONS = 'promotions';
    const MODULE_QUOTATIONS = 'quotations';
    const MODULE_EVENTS = 'events';
    const MODULE_EQUIPMENT = 'equipment';
    const MODULE_SUPPLIERS = 'suppliers';
    const MODULE_CUSTOMERS = 'customers';
    const MODULE_REVIEWS = 'reviews';
    const MODULE_DEPARTMENTS = 'departments';
    const MODULE_POSITIONS = 'positions';
    const MODULE_MEAL_CATEGORIES = 'meal_categories';
    const MODULE_MENU_ITEMS = 'menu_items';
    const MODULE_SALARY_GRADES = 'salary_grades';
    const MODULE_SHIFT_TYPES = 'shift_types';
    const MODULE_DELIVERY_ZONES = 'delivery_zones';
    const MODULE_EVENT_TYPES = 'event_types';

    /**
     * Safe log method - never throws exceptions
     */
    public static function log($action, $module, $recordId = null, $oldValues = null, $newValues = null, $description = null)
    {
        try {
            [$normalizedAction, $normalizedModule] = self::normalizeAuditAction($action, $module);

            return self::create([
                'user_id' => auth()->id(),
                'action' => $normalizedAction,
                'table_name' => $normalizedModule,
                'record_id' => $recordId,
                'old_values' => $oldValues ? (is_string($oldValues) ? $oldValues : json_encode($oldValues)) : null,
                'new_values' => $newValues ? (is_string($newValues) ? $newValues : json_encode($newValues)) : null,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        } catch (\Exception $e) {
            \Log::warning('Failed to log audit action: ' . $e->getMessage());
            return null;
        }
    }

    public static function normalizeAuditAction($action, $module): array
    {
        $module = strtolower(trim((string) $module));
        $action = AuditLogCatalog::normalizeAction((string) $action);

        $module = match ($module) {
            'booking', 'booking_quotations' => 'bookings',
            'quotation' => 'quotations',
            'order' => 'orders',
            'payment', 'payments', 'invoice', 'invoices' => 'finance',
            'notification' => 'notifications',
            'security' => 'security',
            default => $module,
        };

        $mapped = match (true) {
            in_array($action, ['login', 'user_login'], true) && $module === 'auth' => 'user_login',
            in_array($action, ['logout', 'user_logout'], true) && $module === 'auth' => 'user_logout',
            in_array($action, ['failed_login', 'failed_login_attempt'], true) && $module === 'auth' => 'failed_login_attempt',
            in_array($action, ['password', 'password_changed'], true) && $module === 'auth' => 'password_changed',
            in_array($action, ['account_lock', 'account_locked'], true) && $module === 'auth' => 'account_locked',
            in_array($action, ['permission', 'permission_updated', 'permission_changed'], true) && in_array($module, ['roles', 'users', 'security'], true) => 'permission_updated',
            in_array($action, ['create', 'created'], true) && $module === 'bookings' => 'booking_created',
            in_array($action, ['update', 'updated'], true) && $module === 'bookings' => 'booking_updated',
            in_array($action, ['approve', 'approved', 'confirm', 'confirmed'], true) && $module === 'bookings' => 'booking_approved',
            in_array($action, ['reject', 'rejected'], true) && $module === 'bookings' => 'booking_rejected',
            in_array($action, ['cancel', 'cancelled', 'canceled'], true) && $module === 'bookings' => 'booking_cancelled',
            in_array($action, ['complete', 'completed'], true) && $module === 'bookings' => 'booking_completed',
            in_array($action, ['create', 'created'], true) && $module === 'quotations' => 'quotation_created',
            in_array($action, ['update', 'updated'], true) && $module === 'quotations' => 'quotation_updated',
            in_array($action, ['approve', 'approved'], true) && $module === 'quotations' => 'quotation_approved',
            in_array($action, ['reject', 'rejected'], true) && $module === 'quotations' => 'quotation_rejected',
            in_array($action, ['create', 'created'], true) && $module === 'orders' => 'order_created',
            in_array($action, ['update', 'updated'], true) && $module === 'orders' => 'order_updated',
            in_array($action, ['complete', 'completed'], true) && $module === 'orders' => 'order_completed',
            in_array($action, ['payment', 'payment_recorded', 'recorded'], true) && $module === 'finance' => 'payment_recorded',
            in_array($action, ['notification_sent', 'notification_read', 'notification_deleted'], true) => $action,
            default => $action,
        };

        $catalogModule = AuditLogCatalog::moduleForAction($mapped, $module);

        return [$mapped, $catalogModule];
    }

    /**
     * Log login activity
     */
    public static function logLogin($user)
    {
        return self::log(
            self::ACTION_LOGIN,
            self::MODULE_AUTH,
            $user->user_id,
            null,
            ['email' => $user->email, 'role' => $user->roles->first()?->slug],
            'User logged in'
        );
    }

    /**
     * Log logout activity
     */
    public static function logLogout($user)
    {
        return self::log(
            self::ACTION_LOGOUT,
            self::MODULE_AUTH,
            $user->user_id,
            null,
            ['email' => $user->email],
            'User logged out'
        );
    }

    /**
     * Log failed login attempt
     */
    public static function logFailedLogin($email, $ip, $attempts)
    {
        return self::log(
            self::ACTION_FAILED_LOGIN,
            self::MODULE_AUTH,
            null,
            null,
            ['email' => $email, 'ip' => $ip, 'attempts' => $attempts],
            "Failed login attempt for {$email} from IP {$ip}"
        );
    }

    /**
     * Log account lock
     */
    public static function logAccountLock($user, $reason = null)
    {
        return self::log(
            self::ACTION_ACCOUNT_LOCK,
            self::MODULE_AUTH,
            $user->user_id,
            null,
            ['email' => $user->email, 'reason' => $reason],
            'Account locked'
        );
    }

    /**
     * Log booking activity
     */
    public static function logBooking($action, $booking, $oldStatus = null, $newStatus = null, $details = [])
    {
        return self::log(
            $action,
            self::MODULE_BOOKINGS,
            $booking->booking_id,
            $oldStatus ? ['status' => $oldStatus] : null,
            array_merge(
                [
                    'booking_no' => $booking->booking_no,
                    'status' => $newStatus ?? $booking->booking_status,
                    'customer' => $booking->serviceEvent?->customer?->person?->full_name,
                ],
                $details
            ),
            "Booking {$booking->booking_no} {$action}"
        );
    }

    /**
     * Log payment activity
     */
    public static function logPayment($action, $payment, $details = [])
    {
        return self::log(
            $action,
            self::MODULE_PAYMENTS,
            $payment->payment_id,
            null,
            array_merge(
                [
                    'booking_no' => $payment->booking?->booking_no,
                    'amount' => $payment->amount,
                    'method' => $payment->payment_method,
                    'status' => $payment->status,
                ],
                $details
            ),
            "Payment of ₱{$payment->amount} {$action}"
        );
    }

    /**
     * Log inventory movement
     */
    public static function logInventory($action, $ingredient, $quantity, $details = [])
    {
        return self::log(
            self::ACTION_INVENTORY,
            self::MODULE_INVENTORY,
            $ingredient->ingredient_id,
            null,
            array_merge(
                [
                    'ingredient' => $ingredient->name,
                    'quantity' => $quantity,
                    'unit' => $ingredient->unit,
                    'current_stock' => $ingredient->stock?->current_quantity ?? 0,
                ],
                $details
            ),
            "Inventory {$action}: {$quantity} {$ingredient->unit} of {$ingredient->name}"
        );
    }

    /**
     * Log employee activity
     */
    public static function logEmployee($action, $employee, $details = [])
    {
        return self::log(
            $action,
            self::MODULE_EMPLOYEES,
            $employee->employee_id,
            null,
            array_merge(
                [
                    'employee' => $employee->full_name,
                    'employee_code' => $employee->employee_code,
                    'department' => $employee->department?->name,
                    'position' => $employee->position?->title,
                ],
                $details
            ),
            "Employee {$employee->full_name} {$action}"
        );
    }

    /**
     * Log payroll activity
     */
    public static function logPayroll($action, $payroll, $details = [])
    {
        return self::log(
            $action,
            self::MODULE_PAYROLL,
            $payroll->payroll_id,
            null,
            array_merge(
                [
                    'payroll_number' => $payroll->payroll_number,
                    'employee' => $payroll->employee?->full_name,
                    'period' => $payroll->cutoff_start . ' to ' . $payroll->cutoff_end,
                    'amount' => $payroll->items->sum('amount'),
                ],
                $details
            ),
            "Payroll {$payroll->payroll_number} {$action}"
        );
    }

    /**
     * Log attendance activity
     */
    public static function logAttendance($action, $attendance, $details = [])
    {
        return self::log(
            $action,
            self::MODULE_ATTENDANCE,
            $attendance->attendance_id,
            null,
            array_merge(
                [
                    'employee' => $attendance->employee?->full_name,
                    'date' => $attendance->attendance_date,
                    'status' => $attendance->status,
                ],
                $details
            ),
            "Attendance for {$attendance->employee?->full_name} on {$attendance->attendance_date} {$action}"
        );
    }

    /**
     * Log security activity
     */
    public static function logSecurity($action, $user, $details = [])
    {
        return self::log(
            self::ACTION_SECURITY,
            self::MODULE_AUTH,
            $user->user_id,
            null,
            array_merge(['email' => $user->email], $details),
            "Security {$action} for user {$user->email}"
        );
    }

    /**
     * Log configuration change
     */
    public static function logConfig($section, $oldValues, $newValues, $details = [])
    {
        return self::log(
            self::ACTION_CONFIG,
            self::MODULE_SETTINGS,
            null,
            $oldValues,
            array_merge(['section' => $section], $newValues, $details),
            "Configuration updated for {$section}"
        );
    }

    /**
     * Log role/permission change
     */
    public static function logPermission($user, $role, $action, $details = [])
    {
        return self::log(
            self::ACTION_PERMISSION,
            self::MODULE_ROLES,
            $user->user_id,
            null,
            array_merge(
                [
                    'email' => $user->email,
                    'role' => $role->name ?? $role,
                    'action' => $action,
                ],
                $details
            ),
            "Permission {$action} for user {$user->email}"
        );
    }
}