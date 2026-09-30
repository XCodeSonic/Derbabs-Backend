<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PurchaseRequest;
use App\Models\Schedule;
use App\Models\BookingPayment;
use App\Models\User;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /* ============================================================
       CORE DISPATCH
       ============================================================ */

    public function notifyUser(
        int $userId,
        string $type,
        string $title,
        string $message,
        string $priority = 'medium',
        array $data = [],
        ?string $actionUrl = null
    ): Notification {
        $notification = Notification::create([
            'user_id'    => $userId,
            'type'       => $type,
            'title'      => $title,
            'message'    => $message,
            'priority'   => $priority,
            'data'       => $data,
            'action_url' => $actionUrl,
            'is_sent'    => true,
            'sent_at'    => now(),
        ]);

        if (in_array($priority, [Notification::PRIORITY_HIGH, 'critical'])) {
            try {
                $user = User::find($userId);
                if ($user && $user->fcm_token) {
                    app(\App\Http\Controllers\Api\BookingController::class)
                        ->sendMobilePushNotificationPublic($user, $title, $message, $data);
                }
            } catch (\Throwable $e) {
                Log::warning('Push notification failed: ' . $e->getMessage());
            }
        }

        return $notification;
    }

    public function notifyRole(
        string $roleSlug,
        string $type,
        string $title,
        string $message,
        string $priority = 'medium',
        array $data = [],
        ?string $actionUrl = null
    ): void {
        $users = User::query()
            ->whereHas('roles', function ($q) use ($roleSlug) {
                $q->where('slug', $roleSlug);
            })
            ->where('is_active', true)
            ->get();

        foreach ($users as $user) {
            try {
                $this->notifyUser($user->user_id, $type, $title, $message, $priority, $data, $actionUrl);
            } catch (\Throwable $e) {
                Log::warning("Failed to notify user {$user->user_id} of role {$roleSlug}: " . $e->getMessage());
            }
        }
    }

    public function notifySystemEvent(
        string $eventType,
        string $message,
        array $data = [],
        array $roles = ['admin']
    ): void {
        foreach ($roles as $roleSlug) {
            try {
                $this->notifyRole(
                    $roleSlug,
                    $eventType,
                    '📢 System Notification',
                    $message,
                    Notification::PRIORITY_MEDIUM,
                    $data
                );
            } catch (\Throwable $e) {
                Log::warning("System notification to role {$roleSlug} failed: " . $e->getMessage());
            }
        }
    }

    /* ============================================================
       BOOKING LIFECYCLE
       ============================================================ */

    public function bookingRequestReceived(Booking $booking): void
    {
        $customer = $booking->serviceEvent?->customer?->person;
        $event    = $booking->serviceEvent;

        try {
            $this->notifyRole(
                'admin',
                'booking_request',
                '📥 New Booking Request',
                "New booking request {$booking->booking_no} from " . ($customer?->full_name ?? 'Unknown') . ".\n" .
                    "Event: " . ($event?->eventType?->name ?? 'Event') . "\n" .
                    "Date: " . ($event?->event_date?->format('F d, Y') ?? 'TBD') . "\n" .
                    "Guests: " . ($event?->guests_count ?? 0),
                Notification::PRIORITY_HIGH,
                ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                "/admin/bookings/{$booking->booking_id}"
            );
        } catch (\Throwable $e) {
            Log::warning('bookingRequestReceived failed: ' . $e->getMessage());
        }
    }

    public function bookingApproved(Booking $booking): void
    {
        $customer = $booking->serviceEvent?->customer;
        if (!$customer?->user_id) {
            return;
        }

        try {
            $this->notifyUser(
                $customer->user_id,
                'booking_approved',
                '✅ Booking Approved',
                "Great news! Your booking {$booking->booking_no} has been approved.\n\n" .
                    "Please pay the deposit to lock in your date.",
                Notification::PRIORITY_HIGH,
                [
                    'booking_id' => $booking->booking_id,
                    'booking_no' => $booking->booking_no,
                    'type'       => 'booking_approved',
                ],
                "/customer/bookings/{$booking->booking_id}"
            );
        } catch (\Throwable $e) {
            Log::warning('bookingApproved notification failed: ' . $e->getMessage());
        }
    }

    public function depositRequired(Booking $booking): void
    {
        $customer = $booking->serviceEvent?->customer;
        if (!$customer?->user_id) {
            return;
        }

        $total    = (float) ($booking->invoice?->total_amount ?? $booking->quotation?->total_amount ?? 0);
        $required = round($total * 0.3, 2);

        try {
            $this->notifyUser(
                $customer->user_id,
                'deposit_required',
                '💰 Deposit Required',
                "Your booking {$booking->booking_no} has been approved!\n\n" .
                    "Please pay the 30% deposit of ₱" . number_format($required, 2) . " to lock in your date.\n\n" .
                    "You can pay via the Orders page.",
                Notification::PRIORITY_HIGH,
                [
                    'booking_id'       => $booking->booking_id,
                    'booking_no'       => $booking->booking_no,
                    'required_deposit' => $required,
                    'type'             => 'deposit_required',
                ],
                "/customer/bookings/{$booking->booking_id}"
            );
        } catch (\Throwable $e) {
            Log::warning('depositRequired notification failed: ' . $e->getMessage());
        }
    }

    public function bookingCancelled(Booking $booking, ?string $reason = null): void
    {
        $customer = $booking->serviceEvent?->customer;
        if (!$customer?->user_id) {
            return;
        }

        try {
            $this->notifyUser(
                $customer->user_id,
                'booking_cancelled',
                'Booking Cancelled',
                "Your booking {$booking->booking_no} has been cancelled." .
                    ($reason ? "\n\nReason: {$reason}" : ''),
                Notification::PRIORITY_HIGH,
                ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                "/customer/bookings/{$booking->booking_id}"
            );
        } catch (\Throwable $e) {
            Log::warning('bookingCancelled notification failed: ' . $e->getMessage());
        }
    }

    public function bookingRescheduleRequested(Booking $booking, string $newDate, string $newTime, string $reason): void
    {
        try {
            $this->notifyRole(
                'admin',
                'reschedule_requested',
                '🔄 Reschedule Requested',
                "Customer requested a reschedule for {$booking->booking_no}.\n\n" .
                    "New date: {$newDate}\n" .
                    "New time: {$newTime}\n" .
                    "Reason: {$reason}",
                Notification::PRIORITY_HIGH,
                ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                "/admin/bookings/{$booking->booking_id}"
            );
        } catch (\Throwable $e) {
            Log::warning('bookingRescheduleRequested notification failed: ' . $e->getMessage());
        }
    }

    public function rescheduleProposedByAdmin(Booking $booking, Carbon $newDate, ?string $newTime, ?string $reason): void
    {
        $customer = $booking->serviceEvent?->customer;
        if (!$customer?->user_id) {
            return;
        }
        try {
            $this->notifyUser(
                $customer->user_id,
                'reschedule_proposed',
                '📅 New Date Proposed',
                "Our team proposed a new date for booking {$booking->booking_no}.\n\n" .
                    "📅 New date: " . $newDate->format('F d, Y') . "\n" .
                    ($newTime ? "⏰ Time: {$newTime}\n" : '') .
                    ($reason ? "📝 Reason: {$reason}\n" : '') .
                    "\nPlease review and choose an action.",
                Notification::PRIORITY_HIGH,
                [
                    'booking_id' => $booking->booking_id,
                    'booking_no' => $booking->booking_no,
                    'type'       => 'reschedule_proposed',
                ],
                "/customer/bookings/{$booking->booking_id}"
            );
        } catch (\Throwable $e) {
            Log::warning('rescheduleProposedByAdmin notification failed: ' . $e->getMessage());
        }
    }

    public function rescheduleCounterProposed(Booking $booking, Carbon $newDate, ?string $reason): void
    {
        try {
            $this->notifyRole(
                'admin',
                'reschedule_counter_proposed',
                '🔄 Counter Reschedule',
                "Customer proposed a new date for {$booking->booking_no}: " .
                    $newDate->format('F d, Y') . ($reason ? "\nReason: {$reason}" : ''),
                Notification::PRIORITY_HIGH,
                ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                "/admin/bookings/{$booking->booking_id}"
            );
        } catch (\Throwable $e) {
            Log::warning('rescheduleCounterProposed notification failed: ' . $e->getMessage());
        }
    }

    public function depositCutoffWarning(Booking $booking, int $daysUntilEvent): void
    {
        $customer = $booking->serviceEvent?->customer;
        if (!$customer?->user_id) {
            return;
        }
        try {
            $this->notifyUser(
                $customer->user_id,
                'deposit_cutoff',
                '⚠️ Deposit Cutoff Warning',
                "Your event is in {$daysUntilEvent} day(s) but the deposit is still unpaid.\n\n" .
                    "Please settle the deposit to keep your booking confirmed.",
                Notification::PRIORITY_HIGH,
                [
                    'booking_id' => $booking->booking_id,
                    'booking_no' => $booking->booking_no,
                    'type'       => 'deposit_cutoff',
                ],
                "/customer/bookings/{$booking->booking_id}"
            );
        } catch (\Throwable $e) {
            Log::warning('depositCutoffWarning notification failed: ' . $e->getMessage());
        }
    }

    public function eventReminderThreeDays(Booking $booking): void
    {
        $customer = $booking->serviceEvent?->customer;
        if (!$customer?->user_id) {
            return;
        }

        $eventDate = $booking->serviceEvent?->event_date?->format('F d, Y') ?? 'TBD';
        $eventTime = $booking->serviceEvent?->event_time ?? 'TBD';
        $venue     = $booking->serviceEvent?->venue ?? 'TBD';

        try {
            $this->notifyUser(
                $customer->user_id,
                'event_reminder_3d',
                '🎉 Event in 3 Days!',
                "Just a reminder that your event {$booking->booking_no} is 3 days away.\n\n" .
                    "📅 Date: {$eventDate}\n" .
                    "⏰ Time: {$eventTime}\n" .
                    "📍 Venue: {$venue}\n\n" .
                    "We can't wait to serve you!",
                Notification::PRIORITY_HIGH,
                [
                    'booking_id' => $booking->booking_id,
                    'booking_no' => $booking->booking_no,
                    'type'       => 'event_reminder_3d',
                ],
                "/customer/bookings/{$booking->booking_id}"
            );
        } catch (\Throwable $e) {
            Log::warning('eventReminderThreeDays notification failed: ' . $e->getMessage());
        }
    }

    public function paymentReceived(BookingPayment $payment, Customer $customer): void
    {
        if (!$customer?->user_id) {
            return;
        }

        try {
            $this->notifyUser(
                $customer->user_id,
                'payment_received',
                '💰 Payment Received',
                "We have received your payment of ₱" . number_format($payment->amount, 2) .
                    " for booking " . ($payment->booking?->booking_no ?? 'N/A') . ".",
                Notification::PRIORITY_HIGH,
                [
                    'payment_id' => $payment->payment_id,
                    'booking_id' => $payment->booking_id,
                    'amount'     => $payment->amount,
                ],
                "/customer/bookings/{$payment->booking_id}"
            );
        } catch (\Throwable $e) {
            Log::warning('paymentReceived notification failed: ' . $e->getMessage());
        }
    }

    public function balanceReminder(Booking $booking, Customer $customer, float $balance): void
    {
        if (!$customer?->user_id) {
            return;
        }

        try {
            $this->notifyUser(
                $customer->user_id,
                'balance_reminder',
                '⚠️ Balance Reminder',
                "Reminder: You still have an outstanding balance of ₱" .
                    number_format($balance, 2) . " for booking {$booking->booking_no}.",
                Notification::PRIORITY_MEDIUM,
                ['booking_id' => $booking->booking_id, 'balance' => $balance],
                "/customer/bookings/{$booking->booking_id}"
            );
        } catch (\Throwable $e) {
            Log::warning('balanceReminder notification failed: ' . $e->getMessage());
        }
    }

    /* ============================================================
       EVENTS + EQUIPMENT
       ============================================================ */

    public function eventStartsTomorrow(Booking $booking): void
    {
        $customer = $booking->serviceEvent?->customer;
        if ($customer?->user_id) {
            try {
                $this->notifyUser(
                    $customer->user_id,
                    'event_tomorrow',
                    '🎉 Event Tomorrow!',
                    "Your event {$booking->booking_no} is tomorrow. We'll see you there!",
                    Notification::PRIORITY_HIGH,
                    ['booking_id' => $booking->booking_id],
                    "/customer/bookings/{$booking->booking_id}"
                );
            } catch (\Throwable $e) {
                Log::warning('eventStartsTomorrow customer notification failed: ' . $e->getMessage());
            }
        }

        try {
            $this->notifyRole(
                'admin',
                'event_tomorrow',
                '🎉 Event Tomorrow',
                "Event {$booking->booking_no} is scheduled for tomorrow.",
                Notification::PRIORITY_MEDIUM,
                ['booking_id' => $booking->booking_id]
            );
        } catch (\Throwable $e) {
            Log::warning('eventStartsTomorrow admin notification failed: ' . $e->getMessage());
        }
    }

    public function equipmentPreparationNeeded(Booking $booking, Equipment $equipment): void
    {
        try {
            $this->notifyRole(
                'admin',
                'equipment_prep',
                '🛠️ Equipment Preparation Needed',
                "Equipment '{$equipment->name}' needs preparation for booking {$booking->booking_no}.",
                Notification::PRIORITY_MEDIUM,
                ['booking_id' => $booking->booking_id, 'equipment_id' => $equipment->equipment_id]
            );
        } catch (\Throwable $e) {
            Log::warning('equipmentPreparationNeeded notification failed: ' . $e->getMessage());
        }
    }

    public function deliveryPreparationReady(Booking $booking): void
    {
        try {
            $this->notifyRole(
                'admin',
                'delivery_prep_ready',
                '🚚 Delivery Preparation Ready',
                "Delivery preparation for booking {$booking->booking_no} is ready.",
                Notification::PRIORITY_MEDIUM,
                ['booking_id' => $booking->booking_id]
            );
        } catch (\Throwable $e) {
            Log::warning('deliveryPreparationReady notification failed: ' . $e->getMessage());
        }
    }

    public function equipmentReserved(\App\Models\BookingEquipment $equipment, Booking $booking): void
    {
        try {
            $this->notifyRole(
                'admin',
                'equipment_reserved',
                '📦 Equipment Reserved',
                "Equipment reserved for booking {$booking->booking_no}.",
                Notification::PRIORITY_LOW,
                ['booking_id' => $booking->booking_id]
            );
        } catch (\Throwable $e) {
            Log::warning('equipmentReserved notification failed: ' . $e->getMessage());
        }
    }

    public function equipmentDamaged(\App\Models\BookingEquipment $equipment, Booking $booking, int $quantity): void
    {
        try {
            $this->notifyRole(
                'admin',
                'equipment_damaged',
                '⚠️ Equipment Damaged',
                "{$quantity} unit(s) of equipment were damaged after booking {$booking->booking_no}.",
                Notification::PRIORITY_HIGH,
                ['booking_id' => $booking->booking_id]
            );
        } catch (\Throwable $e) {
            Log::warning('equipmentDamaged notification failed: ' . $e->getMessage());
        }
    }

    public function equipmentMissing(\App\Models\BookingEquipment $equipment, Booking $booking, int $quantity): void
    {
        try {
            $this->notifyRole(
                'admin',
                'equipment_missing',
                '❌ Equipment Missing',
                "{$quantity} unit(s) of equipment are missing after booking {$booking->booking_no}.",
                Notification::PRIORITY_HIGH,
                ['booking_id' => $booking->booking_id]
            );
        } catch (\Throwable $e) {
            Log::warning('equipmentMissing notification failed: ' . $e->getMessage());
        }
    }

    public function equipmentReturnOverdue(\App\Models\BookingEquipment $equipment, Booking $booking): void
    {
        try {
            $this->notifyRole(
                'admin',
                'equipment_overdue',
                '⏰ Equipment Return Overdue',
                "Equipment return for booking {$booking->booking_no} is overdue.",
                Notification::PRIORITY_HIGH,
                ['booking_id' => $booking->booking_id]
            );
        } catch (\Throwable $e) {
            Log::warning('equipmentReturnOverdue notification failed: ' . $e->getMessage());
        }
    }

    /* ============================================================
       SCHEDULE + INVENTORY
       ============================================================ */

    public function scheduleAssigned(Schedule $schedule, Employee $employee): void
    {
        if (!$employee->user_id) {
            return;
        }
        try {
            $this->notifyUser(
                $employee->user_id,
                'schedule_assigned',
                '📅 New Schedule Assigned',
                "You have been assigned to a schedule on " .
                    ($schedule->work_date instanceof Carbon ? $schedule->work_date->format('F d, Y') : (string) $schedule->work_date) . ".",
                Notification::PRIORITY_MEDIUM,
                ['schedule_id' => $schedule->schedule_id]
            );
        } catch (\Throwable $e) {
            Log::warning('scheduleAssigned notification failed: ' . $e->getMessage());
        }
    }

    public function scheduleUpdated(Schedule $schedule, Employee $employee, array $changes = []): void
    {
        if (!$employee->user_id) {
            return;
        }
        try {
            $changesText = !empty($changes) ? "\nChanges: " . implode(', ', $changes) : '';
            $this->notifyUser(
                $employee->user_id,
                'schedule_updated',
                '📅 Schedule Updated',
                "Your schedule on " .
                    ($schedule->work_date instanceof Carbon ? $schedule->work_date->format('F d, Y') : (string) $schedule->work_date) .
                    " has been updated." . $changesText,
                Notification::PRIORITY_MEDIUM,
                ['schedule_id' => $schedule->schedule_id]
            );
        } catch (\Throwable $e) {
            Log::warning('scheduleUpdated notification failed: ' . $e->getMessage());
        }
    }

    public function scheduleCancelled(Schedule $schedule, Employee $employee): void
    {
        if (!$employee->user_id) {
            return;
        }
        try {
            $this->notifyUser(
                $employee->user_id,
                'schedule_cancelled',
                '📅 Schedule Cancelled',
                "Your schedule on " .
                    ($schedule->work_date instanceof Carbon ? $schedule->work_date->format('F d, Y') : (string) $schedule->work_date) .
                    " has been cancelled.",
                Notification::PRIORITY_HIGH,
                ['schedule_id' => $schedule->schedule_id]
            );
        } catch (\Throwable $e) {
            Log::warning('scheduleCancelled notification failed: ' . $e->getMessage());
        }
    }

    public function scheduleConflictWarning(string $date, array $bookings): void
    {
        try {
            $this->notifyRole(
                'admin',
                'schedule_conflict',
                '⚠️ Schedule Conflict',
                "Multiple bookings found on {$date} (" . count($bookings) . "). Please review.",
                Notification::PRIORITY_HIGH,
                ['date' => $date, 'booking_count' => count($bookings)]
            );
        } catch (\Throwable $e) {
            Log::warning('scheduleConflictWarning notification failed: ' . $e->getMessage());
        }
    }

    public function lowStockWarning(\App\Models\Ingredient $ingredient, float $current, float $reorder): void
    {
        try {
            $this->notifyRole(
                'admin',
                'low_stock',
                '⚠️ Low Stock',
                "Ingredient '{$ingredient->name}' is low on stock ({$current} left, reorder at {$reorder}).",
                Notification::PRIORITY_HIGH,
                ['ingredient_id' => $ingredient->ingredient_id]
            );
        } catch (\Throwable $e) {
            Log::warning('lowStockWarning notification failed: ' . $e->getMessage());
        }
    }

    public function outOfStock(\App\Models\Ingredient $ingredient): void
    {
        try {
            $this->notifyRole(
                'admin',
                'out_of_stock',
                '❌ Out of Stock',
                "Ingredient '{$ingredient->name}' is out of stock.",
                Notification::PRIORITY_HIGH,
                ['ingredient_id' => $ingredient->ingredient_id]
            );
        } catch (\Throwable $e) {
            Log::warning('outOfStock notification failed: ' . $e->getMessage());
        }
    }

    public function ingredientComputationCompleted(Order $order): void
    {
        try {
            $this->notifyRole(
                'admin',
                'ingredient_computation_done',
                '✅ Ingredient Computation Completed',
                "Ingredient computation for order {$order->order_number} is done.",
                Notification::PRIORITY_LOW,
                ['order_id' => $order->order_id]
            );
        } catch (\Throwable $e) {
            Log::warning('ingredientComputationCompleted notification failed: ' . $e->getMessage());
        }
    }

    /* ============================================================
       ⭐ LEAVE REQUESTS — FIX #8 (was throwing 500 error)
       ============================================================ */

    public function dayOffRequestSubmitted(LeaveRequest $leaveRequest): void
    {
        try {
            $employee = $leaveRequest->employee;
            $name = $employee?->full_name ?? 'An employee';

            $this->notifyRole(
                'admin',
                'leave_request',
                '🌴 New Day Off Request',
                "{$name} submitted a day-off request.\n\n" .
                    "📅 Date: " . $this->formatLeaveRange($leaveRequest) . "\n" .
                    "📝 Reason: " . ($leaveRequest->reason ?? 'No reason provided'),
                Notification::PRIORITY_HIGH,
                [
                    'leave_request_id' => $leaveRequest->leave_request_id ?? $leaveRequest->id,
                    'employee_id' => $leaveRequest->employee_id,
                    'type' => 'day_off_request',
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('dayOffRequestSubmitted notification failed: ' . $e->getMessage());
        }
    }

    public function sickLeaveRequestSubmitted(LeaveRequest $leaveRequest): void
    {
        try {
            $employee = $leaveRequest->employee;
            $name = $employee?->full_name ?? 'An employee';

            $this->notifyRole(
                'admin',
                'leave_request',
                '🤒 New Sick Leave Request',
                "{$name} submitted a sick leave request.\n\n" .
                    "📅 Date: " . $this->formatLeaveRange($leaveRequest) . "\n" .
                    "📝 Reason: " . ($leaveRequest->reason ?? 'No reason provided'),
                Notification::PRIORITY_HIGH,
                [
                    'leave_request_id' => $leaveRequest->leave_request_id ?? $leaveRequest->id,
                    'employee_id' => $leaveRequest->employee_id,
                    'type' => 'sick_leave_request',
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('sickLeaveRequestSubmitted notification failed: ' . $e->getMessage());
        }
    }

    public function shiftSwapRequestSubmitted(LeaveRequest $leaveRequest): void
    {
        try {
            $employee = $leaveRequest->employee;
            $name = $employee?->full_name ?? 'An employee';

            $from = $leaveRequest->swap_from_time ?? $leaveRequest->swap_shift_time ?? '—';
            $to   = $leaveRequest->swap_to_time ?? '—';

            $this->notifyRole(
                'admin',
                'leave_request',
                '🔄 New Shift Swap Request',
                "{$name} submitted a shift swap request.\n\n" .
                    "📅 Date: " . $this->formatLeaveRange($leaveRequest) . "\n" .
                    "⏰ From: {$from} → To: {$to}\n" .
                    "📝 Reason: " . ($leaveRequest->reason ?? 'No reason provided'),
                Notification::PRIORITY_HIGH,
                [
                    'leave_request_id' => $leaveRequest->leave_request_id ?? $leaveRequest->id,
                    'employee_id' => $leaveRequest->employee_id,
                    'type' => 'shift_swap_request',
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('shiftSwapRequestSubmitted notification failed: ' . $e->getMessage());
        }
    }

    public function leaveRequestSubmitted(LeaveRequest $leaveRequest): void
    {
        try {
            $employee = $leaveRequest->employee;
            $name = $employee?->full_name ?? 'An employee';

            $this->notifyRole(
                'admin',
                'leave_request',
                '📆 New Leave Request',
                "{$name} submitted a leave request.\n\n" .
                    "📅 Date: " . $this->formatLeaveRange($leaveRequest) . "\n" .
                    "📝 Reason: " . ($leaveRequest->reason ?? 'No reason provided'),
                Notification::PRIORITY_HIGH,
                [
                    'leave_request_id' => $leaveRequest->leave_request_id ?? $leaveRequest->id,
                    'employee_id' => $leaveRequest->employee_id,
                    'type' => 'leave_request',
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('leaveRequestSubmitted notification failed: ' . $e->getMessage());
        }
    }

    public function leaveRequestApproved(LeaveRequest $leaveRequest): void
    {
        $employee = $leaveRequest->employee;
        if (!$employee?->user_id) {
            return;
        }

        try {
            $this->notifyUser(
                $employee->user_id,
                'leave_request_approved',
                '✅ Leave Request Approved',
                "Your leave request for " . $this->formatLeaveRange($leaveRequest) . " has been approved.",
                Notification::PRIORITY_HIGH,
                [
                    'leave_request_id' => $leaveRequest->leave_request_id ?? $leaveRequest->id,
                    'type' => 'leave_request_approved',
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('leaveRequestApproved notification failed: ' . $e->getMessage());
        }
    }

    public function leaveRequestRejected(LeaveRequest $leaveRequest, ?string $reason = null): void
    {
        $employee = $leaveRequest->employee;
        if (!$employee?->user_id) {
            return;
        }

        try {
            $this->notifyUser(
                $employee->user_id,
                'leave_request_rejected',
                '❌ Leave Request Rejected',
                "Your leave request for " . $this->formatLeaveRange($leaveRequest) . " was rejected." .
                    ($reason ? "\n\nReason: {$reason}" : ''),
                Notification::PRIORITY_HIGH,
                [
                    'leave_request_id' => $leaveRequest->leave_request_id ?? $leaveRequest->id,
                    'type' => 'leave_request_rejected',
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('leaveRequestRejected notification failed: ' . $e->getMessage());
        }
    }

    public function leaveRequestCancelled(LeaveRequest $leaveRequest): void
    {
        try {
            $employee = $leaveRequest->employee;
            $name = $employee?->full_name ?? 'An employee';

            $this->notifyRole(
                'admin',
                'leave_request_cancelled',
                '🚫 Leave Request Cancelled',
                "{$name} cancelled their leave request for " . $this->formatLeaveRange($leaveRequest) . ".",
                Notification::PRIORITY_MEDIUM,
                [
                    'leave_request_id' => $leaveRequest->leave_request_id ?? $leaveRequest->id,
                    'type' => 'leave_request_cancelled',
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('leaveRequestCancelled notification failed: ' . $e->getMessage());
        }
    }

    public function missingTimeoutAlert(Employee $employee, \App\Models\AttendanceLog $attendance): void
    {
        try {
            $this->notifyRole(
                'admin',
                'missing_timeout',
                '⚠️ Missing Time-Out',
                "{$employee->full_name} did not record a time-out for " .
                    ($attendance->attendance_date?->format('M d, Y') ?? 'their last shift') . ".",
                Notification::PRIORITY_HIGH,
                ['attendance_id' => $attendance->attendance_id, 'employee_id' => $employee->employee_id]
            );
        } catch (\Throwable $e) {
            Log::warning('missingTimeoutAlert failed: ' . $e->getMessage());
        }
    }

    /* ============================================================
       HELPERS
       ============================================================ */

    private function formatLeaveRange(LeaveRequest $leaveRequest): string
    {
        $start = $leaveRequest->start_date
            ? Carbon::parse($leaveRequest->start_date)->format('M d, Y')
            : '—';
        $end = $leaveRequest->end_date
            ? Carbon::parse($leaveRequest->end_date)->format('M d, Y')
            : null;

        if (!$end || $end === $start) {
            return $start;
        }

        return "{$start} to {$end}";
    }
}