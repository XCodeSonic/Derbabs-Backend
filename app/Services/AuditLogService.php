<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\AuditLogCatalog;
use Illuminate\Support\Facades\Log;

class AuditLogService
{
    /**
     * Functional audit log writer. Uses AuditLogCatalog so actions like
     * low stock, payment, booking, security and notification events are stored
     * as real audit logs, not just UI labels.
     */
    public function log($action, $module = null, $recordId = null, $old = null, $new = null, ?string $description = null)
    {
        $normalizedAction = AuditLogCatalog::normalizeAction($action);
        $module = $module ?: AuditLogCatalog::moduleForAction($normalizedAction);

        try {
            return AuditLog::log($normalizedAction, $module, $recordId, $old, $new, $description);
        } catch (\Throwable $e) {
            Log::warning('Audit log failed: ' . $e->getMessage());
            return null;
        }
    }

    public function catalog(): array
    {
        return AuditLogCatalog::all();
    }

    public function logAuth(string $action, $user = null, array $details = [])
    {
        return $this->log($action, 'auth', $user?->user_id, null, array_merge([
            'email' => $user?->email,
        ], $details));
    }

    public function logBooking(string $action, $booking = null, array $details = [])
    {
        return $this->log($action, 'bookings', $booking?->booking_id, null, array_merge([
            'booking_no' => $booking?->booking_no,
            'status' => $booking?->booking_status,
        ], $details));
    }

    public function logNotification(string $action, $notification = null, array $details = [])
    {
        return $this->log($action, 'notifications', $notification?->notification_id, null, array_merge([
            'type' => $notification?->type,
            'title' => $notification?->title,
            'user_id' => $notification?->user_id,
        ], $details));
    }

    public function logSecurity(string $action, array $details = [])
    {
        return $this->log($action, 'security', null, null, $details);
    }
}
