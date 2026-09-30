<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Notification extends Model
{
    use SoftDeletes;

    protected $table = 'notifications';
    protected $primaryKey = 'notification_id';
    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
        'sent_at' => 'datetime',
        'is_sent' => 'boolean',
        'starred' => 'boolean',
    ];

    // ==================== NOTIFICATION TYPES CONSTANTS ====================
    
    // Booking & Quotation
    const TYPE_BOOKING_SUBMITTED = 'booking_submitted';
    const TYPE_BOOKING_CONFIRMED = 'booking_confirmed';
    const TYPE_BOOKING_CANCELLED = 'booking_cancelled';
    const TYPE_BOOKING_RESCHEDULED = 'booking_rescheduled';
    const TYPE_BOOKING_REQUEST = 'booking_request';
    const TYPE_QUOTATION_REQUEST = 'quotation_request';
    const TYPE_QUOTATION_ACCEPTED = 'quotation_accepted';
    const TYPE_SCHEDULE_CONFLICT = 'schedule_conflict';
    
    // Inventory
    const TYPE_LOW_STOCK = 'low_stock';
    const TYPE_CRITICAL_STOCK = 'critical_stock';
    const TYPE_OUT_OF_STOCK = 'out_of_stock';
    const TYPE_PURCHASE_REQUEST = 'purchase_request';
    const TYPE_PURCHASE_REQUEST_GENERATED = 'purchase_request_generated';
    const TYPE_INSUFFICIENT_INVENTORY = 'insufficient_inventory';
    const TYPE_EQUIPMENT_SHORTAGE = 'equipment_shortage';
    
    // Order & Kitchen
    const TYPE_ORDER_READY = 'order_ready';
    const TYPE_INGREDIENT_COMPUTED = 'ingredient_computed';
    const TYPE_KITCHEN_PREPARATION = 'kitchen_preparation';
    const TYPE_DELIVERY_PREPARATION = 'delivery_preparation';
    
    // Event
    const TYPE_EVENT_STARTS_TOMORROW = 'event_starts_tomorrow';
    const TYPE_EQUIPMENT_PREPARATION = 'equipment_preparation';
    const TYPE_DELIVERY_READY = 'delivery_ready';
    const TYPE_EVENT_UPCOMING = 'event_upcoming';
    const TYPE_EVENT_STARTED = 'event_started';
    const TYPE_EVENT_COMPLETED = 'event_completed';
    
    // Staff Scheduling
    const TYPE_SCHEDULE_ASSIGNED = 'schedule_assigned';
    const TYPE_SCHEDULE_UPDATED = 'schedule_updated';
    const TYPE_SCHEDULE_CANCELLED = 'schedule_cancelled';
    const TYPE_STAFF_SCHEDULE_CONFLICT = 'staff_schedule_conflict';
    
    // Leave Requests
    const TYPE_LEAVE_REQUEST = 'leave_request';
    const TYPE_SICK_LEAVE = 'sick_leave';
    const TYPE_DAY_OFF_REQUEST = 'day_off_request';
    
    // Payroll & Overtime
    const TYPE_PAYROLL_READY = 'payroll_ready';
    const TYPE_OVERTIME_PENDING = 'overtime_pending';
    const TYPE_OVERTIME_APPROVED = 'overtime_approved';
    const TYPE_OVERTIME_REJECTED = 'overtime_rejected';
    
    // Payment & Billing
    const TYPE_PAYMENT_RECEIVED = 'payment_received';
    const TYPE_BALANCE_REMINDER = 'balance_reminder';
    const TYPE_PAYMENT_DUE = 'payment_due';
    const TYPE_PAYMENT_PROOF_UPLOADED = 'payment_proof_uploaded';
    const TYPE_BALANCE_DUE_REMINDER = 'balance_due_reminder';
    const TYPE_OVERDUE_ACCOUNT = 'overdue_account';
    
    // Equipment
    const TYPE_EQUIPMENT_RESERVED = 'equipment_reserved';
    const TYPE_EQUIPMENT_OVERDUE = 'equipment_overdue';
    const TYPE_EQUIPMENT_DAMAGED = 'equipment_damaged';
    const TYPE_EQUIPMENT_MISSING = 'equipment_missing';
    const TYPE_EQUIPMENT_RETURN_PENDING = 'equipment_return_pending';
    
    // Attendance & Time Tracking
    const TYPE_ATTENDANCE_PENDING = 'attendance_pending';
    const TYPE_MISSING_TIMEOUT = 'missing_timeout';
    const TYPE_UNSCHEDULED_ATTENDANCE = 'unscheduled_attendance';
    
    // Customer Feedback
    const TYPE_CUSTOMER_REVIEW = 'customer_review';
    const TYPE_LOW_RATING = 'low_rating';
    
    // Security
    const TYPE_FAILED_LOGIN = 'failed_login';
    const TYPE_ADMIN_CREATED = 'admin_created';
    const TYPE_PERMISSION_CHANGED = 'permission_changed';


    // Requested operational/system notification aliases
    const TYPE_NEW_BOOKING = 'new_booking';
    const TYPE_BOOKING_APPROVAL = 'booking_approval';
    const TYPE_BOOKING_REJECTION = 'booking_rejection';
    const TYPE_BOOKING_CANCELLATION_REQUEST = 'booking_cancellation_request';
    const TYPE_BOOKING_RESCHEDULE_REQUEST = 'booking_reschedule_request';
    const TYPE_INVENTORY_SHORTAGE = 'inventory_shortage';
    const TYPE_EMPLOYEE_LEAVE_REQUEST = 'employee_leave_request';
    const TYPE_ATTENDANCE_APPROVAL_REQUIRED = 'attendance_approval_required';
    const TYPE_OVERTIME_APPROVAL_REQUIRED = 'overtime_approval_required';
    const TYPE_PAYMENT_VERIFICATION = 'payment_verification';
    const TYPE_PARTIAL_PAYMENT_RECEIVED = 'partial_payment_received';
    const TYPE_OVERDUE_CUSTOMER_ACCOUNT = 'overdue_customer_account';
    const TYPE_EVENT_COMPLETION = 'event_completion';
    const TYPE_MISSING_EQUIPMENT = 'missing_equipment';
    const TYPE_DAMAGED_EQUIPMENT_REPORTED = 'damaged_equipment_reported';
    const TYPE_SECURITY_ALERT = 'security_alert';
    const TYPE_AUDIT_LOG_ALERT = 'audit_log_alert';
    const TYPE_UPCOMING_EVENT_REMINDER = 'upcoming_event_reminder';
    const TYPE_KITCHEN_PREPARATION_REMINDER = 'kitchen_preparation_reminder';
    const TYPE_DELIVERY_PREPARATION_REMINDER = 'delivery_preparation_reminder';
    const TYPE_STAFF_ASSIGNMENT_REQUIRED = 'staff_assignment_required';
    const TYPE_INGREDIENT_RESERVATION_COMPLETED = 'ingredient_reservation_completed';
    const TYPE_DELIVERY_DISPATCHED = 'delivery_dispatched';
    const TYPE_CATERING_TEAM_ARRIVED = 'catering_team_arrived';
    const TYPE_ATTENDANCE_WAITING_VERIFICATION = 'attendance_waiting_verification';
    const TYPE_PAYSLIP_GENERATED = 'payslip_generated';
    const TYPE_PURCHASE_ORDER_APPROVED = 'purchase_order_approved';
    const TYPE_SUPPLIER_DELIVERY_RECEIVED = 'supplier_delivery_received';
    const TYPE_MULTI_DAY_EVENT_NEXT_SCHEDULE_REMINDER = 'multi_day_event_next_schedule_reminder';
    const TYPE_DAILY_SALES_SUMMARY = 'daily_sales_summary';
    const TYPE_WEEKLY_REVENUE_SUMMARY = 'weekly_revenue_summary';
    const TYPE_NEW_CUSTOMER_MESSAGE = 'new_customer_message';
    const TYPE_NEW_PROMOTION_PUBLISHED = 'new_promotion_published';
    const TYPE_BOOKING_CALENDAR_REMINDER = 'booking_calendar_reminder';
    const TYPE_UPCOMING_CUSTOMER_EVENT_REMINDER = 'upcoming_customer_event_reminder';
    const TYPE_NEW_SYSTEM_UPDATE = 'new_system_update';
    const TYPE_REPORT_GENERATION_COMPLETED = 'report_generation_completed';
    const TYPE_DASHBOARD_STATISTICS_UPDATED = 'dashboard_statistics_updated';
    const TYPE_PASSWORD_CHANGED = 'password_changed';
    const TYPE_ROLE_UPDATED = 'role_updated';
    const TYPE_AUDIT_LOG_GENERATED = 'audit_log_generated';
    const TYPE_DATABASE_BACKUP_COMPLETED = 'database_backup_completed';
    const TYPE_DATABASE_BACKUP_FAILED = 'database_backup_failed';
    const TYPE_NEW_DEVICE_LOGIN = 'new_device_login';
    const TYPE_SESSION_EXPIRED = 'session_expired';

    // ==================== PRIORITY LEVELS ====================
    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_CRITICAL = 'critical';

    // ==================== RELATIONSHIPS ====================
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    // ==================== SCOPES ====================
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    public function scopeStarred($query)
    {
        return $query->where('starred', true);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeHighPriority($query)
    {
        return $query->whereIn('priority', [self::PRIORITY_HIGH, self::PRIORITY_CRITICAL]);
    }

    // ==================== HELPER METHODS ====================
    public function markAsRead()
    {
        $this->update(['read_at' => now()]);
    }

    public function markAsUnread()
    {
        $this->update(['read_at' => null]);
    }

    public function toggleStar()
    {
        $this->update(['starred' => !$this->starred]);
    }

    public function isRead()
    {
        return !is_null($this->read_at);
    }

    public function isStarred()
    {
        return $this->starred;
    }

    public function getPriorityColor()
    {
        return match($this->priority) {
            self::PRIORITY_CRITICAL => 'red',
            self::PRIORITY_HIGH => 'orange',
            self::PRIORITY_MEDIUM => 'blue',
            self::PRIORITY_LOW => 'green',
            default => 'default',
        };
    }

    public function getPriorityIcon()
    {
        return match($this->priority) {
            self::PRIORITY_CRITICAL => '🚨',
            self::PRIORITY_HIGH => '⚠️',
            self::PRIORITY_MEDIUM => '📌',
            self::PRIORITY_LOW => 'ℹ️',
            default => '📢',
        };
    }

    // ==================== FACTORY METHODS ====================
    public static function createNotification(
        $userId, 
        $type, 
        $title, 
        $message, 
        $priority = self::PRIORITY_MEDIUM, 
        $data = null, 
        $actionUrl = null
    ) {
        return self::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'priority' => $priority,
            'data' => $data,
            'action_url' => $actionUrl,
            'is_sent' => true,
            'sent_at' => now(),
        ]);
    }

    public static function notifyMultipleUsers($userIds, $type, $title, $message, $priority = self::PRIORITY_MEDIUM, $data = null, $actionUrl = null)
    {
        $notifications = [];
        $now = now();
        
        foreach ($userIds as $userId) {
            $notifications[] = [
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'priority' => $priority,
                'data' => json_encode($data),
                'action_url' => $actionUrl,
                'is_sent' => true,
                'sent_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        
        return self::insert($notifications);
    }

    public function getTimeAgoAttribute()
    {
        return $this->created_at->diffForHumans();
    }

    public function getFormattedDateAttribute()
    {
        return $this->created_at->format('M d, Y h:i A');
    }
}