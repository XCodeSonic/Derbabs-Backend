<?php

namespace App\Support;

use App\Models\Notification;

class NotificationCatalog
{
    /**
     * Functional notification catalog.
     * This is not UI content; it is the source of truth used by the backend to create
     * notification records, set priority/category, and resolve the destination page.
     */
    public const TYPES = [
        // Critical notifications
        'new_booking' => ['title' => '🆕 New Booking', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/booking'],
        'booking_request' => ['title' => '🆕 New Booking', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/booking'],
        'booking_submitted' => ['title' => '🆕 New Booking', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/booking'],
        'quotation_request' => ['title' => '📋 New Quotation Request', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/booking'],
        'booking_approval' => ['title' => '✅ Booking Approval', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/booking'],
        'booking_approved' => ['title' => '✅ Booking Approval', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/booking'],
        'booking_confirmed' => ['title' => '✅ Booking Approval', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/booking'],
        'booking_rejection' => ['title' => '❌ Booking Rejection', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/booking'],
        'booking_rejected' => ['title' => '❌ Booking Rejection', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/booking'],
        'booking_reschedule_request' => ['title' => '🔄 Booking Reschedule Request', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/booking'],
        'booking_rescheduled' => ['title' => '🔄 Booking Reschedule Request', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/booking'],
        'booking_cancellation_request' => ['title' => '🚫 Booking Cancellation Request', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/booking'],
        'booking_cancelled' => ['title' => '🚫 Booking Cancellation Request', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/booking'],
        'inventory_shortage' => ['title' => '📦 Inventory Shortage', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin', 'inventory_staff'], 'destination' => '/inventory'],
        'insufficient_inventory' => ['title' => '📦 Inventory Shortage', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin', 'inventory_staff'], 'destination' => '/inventory'],
        'purchase_request_generated' => ['title' => '🛒 Purchase Request Generated', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin', 'inventory_staff'], 'destination' => '/orders&events/orders'],
        'equipment_shortage' => ['title' => '🪑 Equipment Shortage', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin', 'operations_staff'], 'destination' => '/orders&events/events'],
        'low_stock' => ['title' => '⚠️ Low Stock Alert', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin', 'inventory_staff'], 'destination' => '/inventory'],
        'critical_stock' => ['title' => '⚠️ Low Stock Alert', 'category' => 'critical', 'priority' => Notification::PRIORITY_CRITICAL, 'roles' => ['admin', 'inventory_staff'], 'destination' => '/inventory'],
        'out_of_stock' => ['title' => '❌ Out of Stock Alert', 'category' => 'critical', 'priority' => Notification::PRIORITY_CRITICAL, 'roles' => ['admin', 'inventory_staff'], 'destination' => '/inventory'],
        'employee_leave_request' => ['title' => '👤 Employee Leave Request', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/staff/schedule'],
        'leave_request' => ['title' => '👤 Employee Leave Request', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/staff/schedule'],
        'sick_leave' => ['title' => '🤒 Sick Leave Request', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/staff/schedule'],
        'day_off_request' => ['title' => '🏖️ Day-Off Request', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/staff/schedule'],
        'attendance_approval_required' => ['title' => '⏰ Attendance Approval Required', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/staff/attendance'],
        'attendance_pending' => ['title' => '⏰ Attendance Approval Required', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/staff/attendance'],
        'unscheduled_attendance' => ['title' => '🚨 Unscheduled Attendance', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/staff/attendance'],
        'overtime_approval_required' => ['title' => '⏱️ Overtime Approval Required', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/staff/attendance'],
        'overtime_pending' => ['title' => '⏱️ Overtime Approval Required', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/staff/attendance'],
        'payment_verification' => ['title' => '💳 Payment Verification', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/billing'],
        'payment_verified' => ['title' => '💳 Payment Verification', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/billing'],
        'balance_reminder' => ['title' => '💰 Partial Payment Received', 'category' => 'critical', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin'], 'destination' => '/billing'],
        'balance_due_reminder' => ['title' => '💸 Overdue Customer Account', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/billing'],
        'payment_due' => ['title' => '💳 Payment Verification', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/billing'],
        'payment_proof_uploaded' => ['title' => '💳 Payment Verification', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/billing'],
        'partial_payment_received' => ['title' => '💰 Partial Payment Received', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/billing'],
        'payment_received' => ['title' => '💰 Partial Payment Received', 'category' => 'critical', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin'], 'destination' => '/billing'],
        'overdue_customer_account' => ['title' => '💸 Overdue Customer Account', 'category' => 'critical', 'priority' => Notification::PRIORITY_CRITICAL, 'roles' => ['admin'], 'destination' => '/billing'],
        'overdue_account' => ['title' => '💸 Overdue Customer Account', 'category' => 'critical', 'priority' => Notification::PRIORITY_CRITICAL, 'roles' => ['admin'], 'destination' => '/billing'],
        'event_completion' => ['title' => '🎉 Event Completion', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin', 'event_coordinator'], 'destination' => '/orders&events/events'],
        'event_completed' => ['title' => '🎉 Event Completion', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin', 'event_coordinator'], 'destination' => '/orders&events/events'],
        'missing_equipment' => ['title' => '❌ Missing Equipment', 'category' => 'critical', 'priority' => Notification::PRIORITY_CRITICAL, 'roles' => ['admin', 'operations_staff'], 'destination' => '/orders&events/events'],
        'equipment_missing' => ['title' => '❌ Missing Equipment', 'category' => 'critical', 'priority' => Notification::PRIORITY_CRITICAL, 'roles' => ['admin', 'operations_staff'], 'destination' => '/orders&events/events'],
        'damaged_equipment_reported' => ['title' => '🔧 Damaged Equipment Reported', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin', 'operations_staff'], 'destination' => '/orders&events/events'],
        'equipment_damaged' => ['title' => '🔧 Damaged Equipment Reported', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin', 'operations_staff'], 'destination' => '/orders&events/events'],
        'security_alert' => ['title' => '🔐 Security Alerts', 'category' => 'critical', 'priority' => Notification::PRIORITY_CRITICAL, 'roles' => ['admin'], 'destination' => '/settings'],
        'audit_log_alert' => ['title' => '📑 Audit Log Alert', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/settings'],

        'order_ready' => ['title' => '✅ Booking Approval', 'category' => 'critical', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin', 'operations_staff'], 'destination' => '/orders&events/orders'],
        'ingredient_computed' => ['title' => '📦 Ingredient Reservation Completed', 'category' => 'operational', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin', 'operations_staff'], 'destination' => '/orders&events/orders'],
        'kitchen_preparation' => ['title' => '🍳 Kitchen Preparation Reminder', 'category' => 'operational', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin', 'operations_staff'], 'destination' => '/orders&events/orders'],
        'delivery_preparation' => ['title' => '🚚 Delivery Preparation Reminder', 'category' => 'operational', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin', 'event_coordinator'], 'destination' => '/orders&events/events'],
        'delivery_ready' => ['title' => '🚚 Delivery Preparation Reminder', 'category' => 'operational', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin', 'event_coordinator'], 'destination' => '/orders&events/events'],
        'event_started' => ['title' => '🎉 Event Completion', 'category' => 'operational', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin', 'event_coordinator'], 'destination' => '/orders&events/events'],
        'equipment_preparation' => ['title' => '🪑 Equipment Reserved', 'category' => 'operational', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin', 'operations_staff'], 'destination' => '/orders&events/events'],
        'equipment_overdue' => ['title' => '🔄 Equipment Return Pending', 'category' => 'operational', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin', 'operations_staff'], 'destination' => '/orders&events/events'],

        // Operational notifications
        'upcoming_event_reminder' => ['title' => '📅 Upcoming Event Reminder', 'category' => 'operational', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin', 'event_coordinator'], 'destination' => '/orders&events/events'],
        'event_upcoming' => ['title' => '📅 Upcoming Event Reminder', 'category' => 'operational', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin', 'event_coordinator'], 'destination' => '/orders&events/events'],
        'event_starts_tomorrow' => ['title' => '📅 Upcoming Event Reminder', 'category' => 'operational', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin', 'event_coordinator'], 'destination' => '/orders&events/events'],
        'kitchen_preparation_reminder' => ['title' => '🍳 Kitchen Preparation Reminder', 'category' => 'operational', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin', 'operations_staff'], 'destination' => '/orders&events/orders'],
        'kitchen_preparation' => ['title' => '🍳 Kitchen Preparation Reminder', 'category' => 'operational', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin', 'operations_staff'], 'destination' => '/orders&events/orders'],
        'delivery_preparation_reminder' => ['title' => '🚚 Delivery Preparation Reminder', 'category' => 'operational', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin', 'event_coordinator'], 'destination' => '/orders&events/orders'],
        'delivery_preparation' => ['title' => '🚚 Delivery Preparation Reminder', 'category' => 'operational', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin', 'event_coordinator'], 'destination' => '/orders&events/orders'],
        'staff_assignment_required' => ['title' => '👨‍🍳 Staff Assignment Required', 'category' => 'operational', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/staff/schedule'],
        'schedule_conflict' => ['title' => '👥 Schedule Conflict Detected', 'category' => 'operational', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/staff/schedule'],
        'staff_schedule_conflict' => ['title' => '👥 Schedule Conflict Detected', 'category' => 'operational', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/staff/schedule'],
        'ingredient_reservation_completed' => ['title' => '📦 Ingredient Reservation Completed', 'category' => 'operational', 'priority' => Notification::PRIORITY_LOW, 'roles' => ['admin', 'inventory_staff'], 'destination' => '/inventory'],
        'equipment_reserved' => ['title' => '🪑 Equipment Reserved', 'category' => 'operational', 'priority' => Notification::PRIORITY_LOW, 'roles' => ['admin', 'operations_staff'], 'destination' => '/orders&events/events'],
        'delivery_dispatched' => ['title' => '🚛 Delivery Dispatched', 'category' => 'operational', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin', 'event_coordinator'], 'destination' => '/orders&events/events'],
        'catering_team_arrived' => ['title' => '📍 Catering Team Arrived', 'category' => 'operational', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin', 'event_coordinator'], 'destination' => '/orders&events/events'],
        'missing_timeout' => ['title' => '⏳ Missing Time-Out Reminder', 'category' => 'operational', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin'], 'destination' => '/staff/attendance'],
        'attendance_waiting_verification' => ['title' => '📊 Attendance Waiting for Verification', 'category' => 'operational', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin'], 'destination' => '/staff/attendance'],
        'payroll_ready' => ['title' => '💵 Payroll Ready for Processing', 'category' => 'operational', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin'], 'destination' => '/staff/payroll'],
        'payslip_generated' => ['title' => '📄 Payslip Generated', 'category' => 'operational', 'priority' => Notification::PRIORITY_LOW, 'roles' => ['admin'], 'destination' => '/staff/payroll'],
        'purchase_order_approved' => ['title' => '📝 Purchase Order Approved', 'category' => 'operational', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin', 'inventory_staff'], 'destination' => '/inventory'],
        'supplier_delivery_received' => ['title' => '📥 Supplier Delivery Received', 'category' => 'operational', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin', 'inventory_staff'], 'destination' => '/inventory'],
        'equipment_return_pending' => ['title' => '🔄 Equipment Return Pending', 'category' => 'operational', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin', 'operations_staff'], 'destination' => '/orders&events/events'],
        'multi_day_event_next_schedule_reminder' => ['title' => '📅 Multi-Day Event Next Schedule Reminder', 'category' => 'operational', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin', 'event_coordinator'], 'destination' => '/orders&events/events'],

        // Informational and system notifications
        'daily_sales_summary' => ['title' => '📈 Daily Sales Summary', 'category' => 'informational', 'priority' => Notification::PRIORITY_LOW, 'roles' => ['admin'], 'destination' => '/reports'],
        'weekly_revenue_summary' => ['title' => '📊 Weekly Revenue Summary', 'category' => 'informational', 'priority' => Notification::PRIORITY_LOW, 'roles' => ['admin'], 'destination' => '/reports'],
        'customer_review' => ['title' => '⭐ New Customer Feedback', 'category' => 'informational', 'priority' => Notification::PRIORITY_LOW, 'roles' => ['admin'], 'destination' => '/customer-feedback'],
        'low_rating' => ['title' => '⭐ Low Customer Rating Alert', 'category' => 'informational', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin'], 'destination' => '/customer-feedback'],
        'new_customer_message' => ['title' => '💬 New Customer Message', 'category' => 'informational', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin'], 'destination' => '/customer-feedback'],
        'new_promotion_published' => ['title' => '📢 New Promotion Published', 'category' => 'informational', 'priority' => Notification::PRIORITY_LOW, 'roles' => ['admin'], 'destination' => '/menu'],
        'booking_calendar_reminder' => ['title' => '📆 Booking Calendar Reminder', 'category' => 'informational', 'priority' => Notification::PRIORITY_LOW, 'roles' => ['admin'], 'destination' => '/booking'],
        'upcoming_customer_event_reminder' => ['title' => '🎂 Upcoming Customer Event Reminder', 'category' => 'informational', 'priority' => Notification::PRIORITY_LOW, 'roles' => ['admin'], 'destination' => '/customer-feedback'],
        'new_system_update' => ['title' => '📜 New System Update', 'category' => 'informational', 'priority' => Notification::PRIORITY_LOW, 'roles' => ['admin'], 'destination' => '/settings'],
        'report_generation_completed' => ['title' => '📄 Report Generation Completed', 'category' => 'informational', 'priority' => Notification::PRIORITY_LOW, 'roles' => ['admin'], 'destination' => '/reports'],
        'dashboard_statistics_updated' => ['title' => '📈 Dashboard Statistics Updated', 'category' => 'informational', 'priority' => Notification::PRIORITY_LOW, 'roles' => ['admin'], 'destination' => '/dashboard'],
        'failed_login' => ['title' => '🚫 Multiple Failed Login Attempts', 'category' => 'system', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/settings'],
        'admin_created' => ['title' => '👤 New Admin Account Created', 'category' => 'system', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/settings'],
        'password_changed' => ['title' => '🔑 Password Changed', 'category' => 'system', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin'], 'destination' => '/settings'],
        'role_updated' => ['title' => '🛡️ User Role Updated', 'category' => 'system', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin'], 'destination' => '/settings'],
        'permission_changed' => ['title' => '🔒 Permission Changed', 'category' => 'system', 'priority' => Notification::PRIORITY_MEDIUM, 'roles' => ['admin'], 'destination' => '/settings'],
        'audit_log_generated' => ['title' => '📝 Audit Log Generated', 'category' => 'system', 'priority' => Notification::PRIORITY_LOW, 'roles' => ['admin'], 'destination' => '/settings'],
        'database_backup_completed' => ['title' => '💾 Database Backup Completed', 'category' => 'system', 'priority' => Notification::PRIORITY_LOW, 'roles' => ['admin'], 'destination' => '/settings'],
        'database_backup_failed' => ['title' => '⚠️ Backup Failed', 'category' => 'system', 'priority' => Notification::PRIORITY_CRITICAL, 'roles' => ['admin'], 'destination' => '/settings'],
        'new_device_login' => ['title' => '🌐 New Device Login Detected', 'category' => 'system', 'priority' => Notification::PRIORITY_HIGH, 'roles' => ['admin'], 'destination' => '/settings'],
        'session_expired' => ['title' => '⌛ Session Expired', 'category' => 'system', 'priority' => Notification::PRIORITY_LOW, 'roles' => ['admin'], 'destination' => '/login'],
    ];

    public static function definition(?string $type): array
    {
        $key = self::normalizeType($type);
        return self::TYPES[$key] ?? [
            'title' => '📢 System Notification',
            'category' => 'informational',
            'priority' => Notification::PRIORITY_MEDIUM,
            'roles' => ['admin'],
            'destination' => '/dashboard',
        ];
    }

    public static function normalizeType(?string $type): string
    {
        return strtolower(trim(str_replace([' ', '-'], '_', (string) $type)));
    }

    public static function destination(?string $type, array $data = [], ?string $fallback = null): string
    {
        if ($fallback) {
            $mapped = self::mapBackendUrlToFrontend($fallback);
            if ($mapped) {
                return $mapped;
            }
        }

        $definition = self::definition($type);
        return self::interpolateDestination($definition['destination'] ?? '/dashboard', $data);
    }

    public static function decorate(Notification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $definition = self::definition($notification->type);
        $destination = self::destination($notification->type, $data, $notification->action_url);

        return array_merge($notification->toArray(), [
            'notification_category' => $definition['category'] ?? 'informational',
            'display_type' => $definition['title'] ?? $notification->title,
            'destination_url' => $destination,
            'action_url' => $destination,
            'metadata' => $data,
        ]);
    }

    public static function all(): array
    {
        return collect(self::TYPES)->map(function ($definition, $type) {
            return array_merge(['type' => $type], $definition);
        })->values()->all();
    }

    private static function mapBackendUrlToFrontend(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (!str_starts_with($url, '/admin') && !str_starts_with($url, '/api') && self::isFrontendRoute($url)) {
            return $url;
        }

        $lower = strtolower($url);
        return match (true) {
            str_contains($lower, 'booking'), str_contains($lower, 'quotation') => '/booking',
            str_contains($lower, 'order'), str_contains($lower, 'kitchen'), str_contains($lower, 'ingredient'), str_contains($lower, 'purchase') => '/orders&events/orders',
            str_contains($lower, 'event'), str_contains($lower, 'delivery'), str_contains($lower, 'equipment') => '/orders&events/events',
            str_contains($lower, 'inventory'), str_contains($lower, 'stock') => '/inventory',
            str_contains($lower, 'payment'), str_contains($lower, 'invoice'), str_contains($lower, 'billing') => '/billing',
            str_contains($lower, 'payroll') => '/staff/payroll',
            str_contains($lower, 'attendance'), str_contains($lower, 'time') => '/staff/attendance',
            str_contains($lower, 'schedule'), str_contains($lower, 'leave'), str_contains($lower, 'employee'), str_contains($lower, 'staff') => '/staff/schedule',
            str_contains($lower, 'customer'), str_contains($lower, 'review'), str_contains($lower, 'message') => '/customer-feedback',
            str_contains($lower, 'audit'), str_contains($lower, 'security'), str_contains($lower, 'setting') => '/settings',
            str_contains($lower, 'report') => '/reports',
            str_contains($lower, 'menu'), str_contains($lower, 'promotion') => '/menu',
            default => null,
        };
    }

    private static function isFrontendRoute(string $url): bool
    {
        return in_array($url, [
            '/dashboard', '/booking', '/orders&events/orders', '/orders&events/events', '/inventory',
            '/billing', '/staff/attendance', '/staff/schedule', '/staff/payroll', '/staff/directory',
            '/settings', '/reports', '/menu', '/customer-feedback', '/notifications', '/login'
        ], true);
    }

    private static function interpolateDestination(string $destination, array $data): string
    {
        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $destination = str_replace('{' . $key . '}', (string) $value, $destination);
            }
        }
        return $destination;
    }
}
