<?php

namespace App\Support;

class AuditLogCatalog
{
    /**
     * Functional audit-log catalog.
     * These actions are used as log action/module values and descriptions, not as a static UI list.
     */
    public const GROUPS = [
        'auth' => ['label' => 'Authentication Logs', 'actions' => ['user_login','user_logout','failed_login_attempt','password_changed','password_reset','new_device_login','session_expired','account_locked','account_unlocked']],
        'users' => ['label' => 'User Management Logs', 'actions' => ['user_created','user_updated','user_deleted','user_activated','user_deactivated','role_assigned','role_changed','permission_updated']],
        'bookings' => ['label' => 'Booking Logs', 'actions' => ['booking_created','booking_updated','booking_approved','booking_rejected','booking_cancelled','booking_rescheduled','booking_completed','booking_converted_to_order']],
        'quotations' => ['label' => 'Quotation Logs', 'actions' => ['quotation_created','quotation_updated','discount_applied','additional_charges_added','quotation_approved','quotation_accepted','quotation_rejected','quotation_converted_to_booking']],
        'orders' => ['label' => 'Order Logs', 'actions' => ['order_created','order_updated','order_status_changed','ingredient_computation_generated','kitchen_preparation_generated','delivery_preparation_generated','order_completed']],
        'inventory' => ['label' => 'Inventory Logs', 'actions' => ['stock_added','stock_adjusted','stock_reserved','stock_released','stock_deducted','stock_returned','stock_wasted','manual_adjustment','equipment_reserved','equipment_released','equipment_returned','equipment_damaged','equipment_missing','equipment_replaced']],
        'purchases' => ['label' => 'Purchase Logs', 'actions' => ['purchase_request_created','purchase_request_approved','purchase_request_rejected','purchase_order_created','supplier_assigned','stock_received']],
        'employees' => ['label' => 'Employee Logs', 'actions' => ['employee_added','employee_updated','employee_archived','department_changed','position_changed','salary_grade_changed']],
        'schedules' => ['label' => 'Schedule Logs', 'actions' => ['schedule_created','schedule_updated','schedule_deleted','employee_assigned','employee_removed','schedule_conflict_detected']],
        'employee_requests' => ['label' => 'Employee Request Logs', 'actions' => ['leave_request_submitted','leave_approved','leave_rejected','sick_leave_submitted','sick_leave_approved','day_off_submitted','day_off_approved']],
        'attendance' => ['label' => 'Attendance Logs', 'actions' => ['time_in_recorded','time_out_recorded','attendance_approved','attendance_rejected','unscheduled_attendance_approved','late_recorded','overtime_approved','overtime_rejected']],
        'payroll' => ['label' => 'Payroll Logs', 'actions' => ['payroll_generated','payroll_approved','payslip_generated','salary_adjusted','overtime_added','deduction_updated','payroll_released']],
        'finance' => ['label' => 'Finance Logs', 'actions' => ['invoice_generated','payment_recorded','payment_verified','partial_payment_received','full_payment_received','credit_account_created','debt_settled','refund_processed']],
        'events' => ['label' => 'Event Logs', 'actions' => ['event_created','event_updated','event_started','event_completed','staff_assigned','equipment_assigned','delivery_started','delivery_completed']],
        'menu' => ['label' => 'Menu Management Logs', 'actions' => ['menu_added','menu_updated','menu_deleted','menu_price_changed','menu_image_updated','category_added','package_updated','promotion_added']],
        'customers' => ['label' => 'Customer Logs', 'actions' => ['customer_registered','customer_updated_profile','customer_submitted_review','admin_replied_to_review','customer_sent_message']],
        'settings' => ['label' => 'Settings Logs', 'actions' => ['system_settings_updated','pricing_rules_changed','payroll_settings_updated','inventory_settings_updated','notification_settings_updated','payment_settings_updated']],
        'reports' => ['label' => 'Report Logs', 'actions' => ['sales_report_generated','payroll_report_generated','inventory_report_generated','financial_report_generated','analytics_exported']],
        'notifications' => ['label' => 'Notification Logs', 'actions' => ['notification_sent','notification_read','notification_deleted']],
        'security' => ['label' => 'Security Logs', 'actions' => ['multiple_failed_login_attempts','unauthorized_access_attempt','user_permission_changed','database_backup_completed','database_backup_failed','system_error_logged']],
    ];

    public static function all(): array
    {
        return self::GROUPS;
    }

    public static function moduleForAction(string $action, ?string $fallback = null): string
    {
        $normalized = self::normalizeAction($action);
        foreach (self::GROUPS as $module => $group) {
            if (in_array($normalized, $group['actions'], true)) {
                return $module;
            }
        }
        return $fallback ?: 'system';
    }

    public static function normalizeAction(string $action): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $action), '_'));
    }

    public static function label(string $action): string
    {
        return str_replace(' ', ' ', ucwords(str_replace('_', ' ', self::normalizeAction($action))));
    }
}
