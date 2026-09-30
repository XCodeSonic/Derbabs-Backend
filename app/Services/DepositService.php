<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\BookingPayment;
use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DepositService
{
    private function depositPercentage(): float
    {
        return app(BookingPolicyService::class)->depositPercentage();
    }

    /**
     * Check all confirmed bookings for deposit status
     * Returns bookings with unpaid deposits that are near the event date
     */
    public function getPendingDeposits(): array
    {
        $bookings = Booking::with([
            'serviceEvent.customer.person',
            'serviceEvent.eventType',
            'payments',
            'invoice'
        ])
        ->whereIn('booking_status', ['confirmed', 'pending_approval'])
        ->get();

        $pct = $this->depositPercentage();
        $pendingDeposits = [];
        $upcomingDeadlines = [];

        foreach ($bookings as $booking) {
            $totalAmount = (float) ($booking->invoice?->total_amount ?? $booking->quotation?->total_amount ?? 0);
            $requiredDeposit = $booking->required_deposit ?? round($totalAmount * ($pct / 100), 2);
            $paidDeposit = $booking->payments()
                ->where('payment_type', 'deposit')
                ->where('status', 'completed')
                ->sum('amount');

            $isDepositPaid = $paidDeposit >= $requiredDeposit;
            $eventDate = $booking->serviceEvent?->event_date;
            $daysUntilEvent = $eventDate ? Carbon::now()->diffInDays($eventDate) : null;

            // Check if deposit is not paid and event is approaching
            if (!$isDepositPaid && $daysUntilEvent !== null && $daysUntilEvent <= 7) {
                $pendingDeposits[] = [
                    'booking_id' => $booking->booking_id,
                    'booking_no' => $booking->booking_no,
                    'customer_name' => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                    'customer_email' => $booking->serviceEvent?->customer?->person?->email,
                    'customer_phone' => $booking->serviceEvent?->customer?->person?->phone,
                    'event_date' => $eventDate?->toDateString(),
                    'days_until_event' => $daysUntilEvent,
                    'total_amount' => $totalAmount,
                    'required_deposit' => $requiredDeposit,
                    'paid_deposit' => $paidDeposit,
                    'remaining_deposit' => max(0, $requiredDeposit - $paidDeposit),
                    'is_deposit_paid' => $isDepositPaid,
                    'booking_status' => $booking->booking_status,
                    'notification_sent' => $this->hasDepositReminderSent($booking),
                ];
            }

            // Track upcoming deadlines (2 days before event)
            if ($daysUntilEvent !== null && $daysUntilEvent <= 2 && !$isDepositPaid) {
                $upcomingDeadlines[] = [
                    'booking_id' => $booking->booking_id,
                    'booking_no' => $booking->booking_no,
                    'customer_name' => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                    'event_date' => $eventDate?->toDateString(),
                    'days_until_event' => $daysUntilEvent,
                    'warning' => $daysUntilEvent <= 0 ? 'EVENT IS TODAY OR PAST - DEPOSIT NOT PAID' : 'Deposit must be paid within 2 days',
                ];
            }
        }

        return [
            'pending_deposits' => $pendingDeposits,
            'upcoming_deadlines' => $upcomingDeadlines,
            'total_pending' => count($pendingDeposits),
            'total_upcoming_deadlines' => count($upcomingDeadlines),
        ];
    }

    /**
     * Check if a deposit reminder has been sent for this booking
     */
    private function hasDepositReminderSent(Booking $booking): bool
    {
        return Notification::where('user_id', $booking->serviceEvent?->customer?->user_id)
            ->where('type', 'deposit_reminder')
            ->where('data', 'like', '%"booking_id":' . $booking->booking_id . '%')
            ->exists();
    }

    /**
     * Send deposit reminders for pending deposits
     */
    public function sendDepositReminders(): int
    {
        $data = $this->getPendingDeposits();
        $notificationService = app(NotificationService::class);
        $sentCount = 0;

        foreach ($data['pending_deposits'] as $pending) {
            // Skip if already sent
            if ($pending['notification_sent']) {
                continue;
            }

            $booking = Booking::find($pending['booking_id']);
            if (!$booking) continue;

            $customer = $booking->serviceEvent?->customer;
            if (!$customer || !$customer->user_id) continue;

            $notificationService->notifyUser(
                $customer->user_id,
                'deposit_reminder',
                '⚠️ Deposit Payment Reminder',
                "Dear {$pending['customer_name']},\n\n" .
                "This is a reminder that your deposit of ₱" . number_format($pending['remaining_deposit'], 2) .
                " for booking {$pending['booking_no']} is still pending.\n\n" .
                "Your event is scheduled on {$pending['event_date']} (in {$pending['days_until_event']} days).\n" .
                "Please complete your deposit payment to confirm your booking.\n\n" .
                "If you have already made the payment, please disregard this message.\n\n" .
                "Thank you!",
                Notification::PRIORITY_HIGH,
                ['booking_id' => $pending['booking_id'], 'booking_no' => $pending['booking_no']],
                "/customer/bookings/{$pending['booking_id']}"
            );

            $sentCount++;
        }

        return $sentCount;
    }

    /**
     * Auto-cancel bookings with unpaid deposits (2 days before event)
     */
    public function autoCancelUnpaidDeposits(): array
    {
        $data = $this->getPendingDeposits();
        $cancelled = [];

        foreach ($data['upcoming_deadlines'] as $deadline) {
            if ($deadline['days_until_event'] <= 0) {
                $booking = Booking::find($deadline['booking_id']);
                if ($booking && $booking->booking_status !== 'cancelled') {
                    DB::transaction(function () use ($booking, &$cancelled) {
                        $booking->update([
                            'booking_status' => 'cancelled',
                            'cancellation_reason' => 'Auto-cancelled: Deposit not paid 2 days before event',
                        ]);

                        // Notify customer
                        $customer = $booking->serviceEvent?->customer;
                        if ($customer && $customer->user_id) {
                            $notificationService = app(NotificationService::class);
                            $notificationService->notifyUser(
                                $customer->user_id,
                                'booking_cancelled_deposit',
                                'Booking Cancelled - Deposit Not Paid',
                                "Dear {$customer->person?->full_name},\n\n" .
                                "Your booking {$booking->booking_no} has been automatically cancelled because the required deposit was not paid within the required timeframe.\n\n" .
                                "If you wish to rebook, please contact our team.\n\n" .
                                "Thank you.",
                                Notification::PRIORITY_HIGH,
                                ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no]
                            );
                        }

                        $cancelled[] = $booking->booking_no;
                    });
                }
            }
        }

        return [
            'cancelled' => $cancelled,
            'count' => count($cancelled),
        ];
    }
}