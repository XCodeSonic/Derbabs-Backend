<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Invoice;
use App\Models\Refund;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RefundService
{
    protected NotificationService $notificationService;
    protected BookingPolicyService $policyService;

    public function __construct(
        NotificationService $notificationService,
        BookingPolicyService $policyService
    ) {
        $this->notificationService = $notificationService;
        $this->policyService = $policyService;
    }

    /**
     * Submit a refund request
     */
    public function requestRefund(array $data, ?int $userId = null): Refund
    {
        $bookingId = $data['booking_id'] ?? null;
        $invoiceId = $data['invoice_id'] ?? null;

        if (!$bookingId && !$invoiceId) {
            throw ValidationException::withMessages([
                'refund' => ['Either booking_id or invoice_id is required.'],
            ]);
        }

        // Prevent duplicate pending requests
        $existingQuery = Refund::pending();
        if ($bookingId) {
            $existingQuery->where('booking_id', $bookingId);
        }
        if ($invoiceId) {
            $existingQuery->where('invoice_id', $invoiceId);
        }

        if ($existingQuery->exists()) {
            throw ValidationException::withMessages([
                'refund' => ['A refund request is already pending for this record.'],
            ]);
        }

        $booking = $bookingId ? Booking::with(['payments', 'invoice', 'serviceEvent.customer.person'])->find($bookingId) : null;
        $invoice = $invoiceId ? Invoice::with(['booking.payments'])->find($invoiceId) : null;

        // Calculate deposit snapshot
        $depositSnapshot = 0;
        if ($booking) {
            $depositSnapshot = (float) $booking->payments()
                ->where('status', 'completed')
                ->where('payment_type', 'deposit')
                ->sum('amount');
        } elseif ($invoice && $invoice->booking) {
            $depositSnapshot = (float) $invoice->booking->payments()
                ->where('status', 'completed')
                ->where('payment_type', 'deposit')
                ->sum('amount');
        }

        return DB::transaction(function () use ($data, $bookingId, $invoiceId, $depositSnapshot, $userId) {
            $refund = Refund::create([
                'refund_number' => Refund::generateRefundNumber(),
                'booking_id' => $bookingId,
                'invoice_id' => $invoiceId,
                'requested_by' => $userId,
                'amount' => 0, // Admin fills at approval
                'deposit_snapshot' => $depositSnapshot,
                'status' => Refund::STATUS_PENDING,
                'reason' => $data['reason'] ?? null,
                'requested_at' => now(),
                'source' => $invoiceId ? Refund::SOURCE_INVOICE : Refund::SOURCE_BOOKING,
            ]);

            // Update invoice refund status
            if ($invoiceId) {
                Invoice::where('invoice_id', $invoiceId)->update([
                    'refund_status' => Refund::STATUS_PENDING,
                    'refund_amount' => 0,
                ]);
            } elseif ($bookingId && $booking = Booking::find($bookingId)) {
                if ($booking->invoice) {
                    $booking->invoice->update([
                        'refund_status' => Refund::STATUS_PENDING,
                        'refund_amount' => 0,
                    ]);
                }
            }

            // Notify admins
            try {
                $bookingNo = $booking?->booking_no ?? $invoice?->invoice_number ?? 'N/A';
                $this->notificationService->notifyRole(
                    'admin',
                    'refund_requested',
                    '💰 Refund Request Pending',
                    "A refund request ({$refund->refund_number}) has been submitted for {$bookingNo}.\n" .
                        "Deposit on file: ₱" . number_format($depositSnapshot, 2),
                    \App\Models\Notification::PRIORITY_HIGH,
                    ['refund_id' => $refund->refund_id, 'booking_id' => $bookingId],
                    "/admin/refunds"
                );
            } catch (\Throwable $e) {
                Log::warning('Refund request notification failed: ' . $e->getMessage());
            }

            return $refund;
        });
    }

    /**
     * Approve a refund request
     */
    public function approveRefund(Refund $refund, array $data, ?int $userId = null): Refund
    {
        if (!$refund->isPending()) {
            throw ValidationException::withMessages([
                'refund' => ['Only pending refunds can be approved.'],
            ]);
        }

        $approvedAmount = (float) ($data['approved_amount'] ?? 0);
        if ($approvedAmount < 0) {
            throw ValidationException::withMessages([
                'approved_amount' => ['Approved amount cannot be negative.'],
            ]);
        }

        return DB::transaction(function () use ($refund, $data, $approvedAmount, $userId) {
            $refund->update([
                'status' => Refund::STATUS_APPROVED,
                'approved_amount' => $approvedAmount,
                'amount' => $approvedAmount,
                'approved_by' => $userId,
                'approved_at' => now(),
                'admin_notes' => $data['admin_notes'] ?? null,
            ]);

            // Update invoice refund status
            if ($refund->invoice_id) {
                Invoice::where('invoice_id', $refund->invoice_id)->update([
                    'refund_status' => Refund::STATUS_APPROVED,
                    'refund_amount' => $approvedAmount,
                ]);
            } elseif ($refund->booking_id) {
                $booking = Booking::find($refund->booking_id);
                if ($booking?->invoice) {
                    $booking->invoice->update([
                        'refund_status' => Refund::STATUS_APPROVED,
                        'refund_amount' => $approvedAmount,
                    ]);
                }
            }

            // Notify cashiers
            try {
                $this->notificationService->notifyRole(
                    'cashier',
                    'refund_approved',
                    '✅ Refund Request Approved',
                    "Refund {$refund->refund_number} was approved for ₱" . number_format($approvedAmount, 2) .
                        ". Please confirm and release.",
                    \App\Models\Notification::PRIORITY_HIGH,
                    ['refund_id' => $refund->refund_id],
                    "/admin/refunds"
                );
            } catch (\Throwable $e) {
                Log::warning('Refund approval notification failed: ' . $e->getMessage());
            }

            return $refund->fresh();
        });
    }

    /**
     * Release an approved refund
     */
    public function releaseRefund(Refund $refund, array $data, ?int $userId = null): Refund
    {
        if (!$refund->isApproved()) {
            throw ValidationException::withMessages([
                'refund' => ['Only approved refunds can be released.'],
            ]);
        }

        $releaseAmount = (float) ($data['released_amount'] ?? $refund->approved_amount ?? 0);
        if ($releaseAmount <= 0) {
            throw ValidationException::withMessages([
                'released_amount' => ['Release amount must be greater than 0.'],
            ]);
        }

        $method = strtolower(str_replace(' ', '_', $data['payment_method'] ?? 'cash'));
        $allowedMethods = ['cash', 'gcash', 'maya', 'bank_transfer', 'card', 'check'];
        if (!in_array($method, $allowedMethods, true)) {
            $method = 'cash';
        }

        return DB::transaction(function () use ($refund, $data, $releaseAmount, $method, $userId) {
            $booking = $refund->booking ?? ($refund->invoice?->booking);

            // Create the refund payment record
            $payment = null;
            if ($booking) {
                $payment = BookingPayment::create([
                    'booking_id' => $booking->booking_id,
                    'payment_number' => 'REF-' . now()->format('YmdHisv') . '-' . $booking->booking_id . '-' . random_int(100, 999),
                    'amount' => $releaseAmount,
                    'payment_method' => $method,
                    'payment_type' => 'refund',
                    'reference_number' => $data['reference_number'] ?? null,
                    'notes' => trim('Refund released: ' . ($data['notes'] ?? '')),
                    'status' => 'completed',
                    'payment_date' => now(),
                    'verified_by' => $userId,
                    'verified_at' => now(),
                ]);

                // Sync invoice
                $this->synchronizeInvoice($booking);
            }

            $refund->update([
                'status' => Refund::STATUS_RELEASED,
                'released_amount' => $releaseAmount,
                'payment_method' => $method,
                'reference_number' => $data['reference_number'] ?? null,
                'released_by' => $userId,
                'released_at' => now(),
                'payment_id' => $payment?->payment_id,
            ]);

            // Update invoice refund status
            if ($refund->invoice_id) {
                Invoice::where('invoice_id', $refund->invoice_id)->update([
                    'refund_status' => Refund::STATUS_RELEASED,
                    'refund_released' => $releaseAmount,
                ]);
            } elseif ($refund->booking_id) {
                $booking = Booking::find($refund->booking_id);
                if ($booking?->invoice) {
                    $booking->invoice->update([
                        'refund_status' => Refund::STATUS_RELEASED,
                        'refund_released' => $releaseAmount,
                    ]);
                }
            }

            // Cancel the booking if applicable
            if ($booking && $data['cancel_booking'] ?? true) {
                $booking->update([
                    'booking_status' => 'cancelled',
                    'cancellation_reason' => trim('Refund released: ' . ($data['notes'] ?? '')),
                ]);
                $booking->serviceEvent?->update(['status' => 'cancelled']);
            }

            // Notify customer
            try {
                $customer = $booking?->serviceEvent?->customer;
                if ($customer?->user_id) {
                    $this->notificationService->notifyUser(
                        $customer->user_id,
                        'refund_released',
                        '💵 Refund Released',
                        "Your refund of ₱" . number_format($releaseAmount, 2) . " ({$refund->refund_number}) has been released.",
                        \App\Models\Notification::PRIORITY_HIGH,
                        ['refund_id' => $refund->refund_id, 'booking_id' => $booking?->booking_id]
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Refund release notification failed: ' . $e->getMessage());
            }

            return $refund->fresh();
        });
    }

    /**
     * Reject a refund request
     */
    public function rejectRefund(Refund $refund, string $reason, ?int $userId = null): Refund
    {
        if (!$refund->isPending()) {
            throw ValidationException::withMessages([
                'refund' => ['Only pending refunds can be rejected.'],
            ]);
        }

        return DB::transaction(function () use ($refund, $reason, $userId) {
            $refund->update([
                'status' => Refund::STATUS_REJECTED,
                'rejection_reason' => $reason,
                'rejected_by' => $userId,
                'rejected_at' => now(),
            ]);

            // Update invoice refund status
            if ($refund->invoice_id) {
                Invoice::where('invoice_id', $refund->invoice_id)->update([
                    'refund_status' => Refund::STATUS_REJECTED,
                ]);
            } elseif ($refund->booking_id) {
                $booking = Booking::find($refund->booking_id);
                if ($booking?->invoice) {
                    $booking->invoice->update([
                        'refund_status' => Refund::STATUS_REJECTED,
                    ]);
                }
            }

            return $refund->fresh();
        });
    }

    /**
     * Admin direct refund (bypass approval flow)
     */
    public function adminDirectRefund(array $data, ?int $userId = null): Refund
    {
        $bookingId = $data['booking_id'] ?? null;
        $invoiceId = $data['invoice_id'] ?? null;
        $amount = (float) ($data['amount'] ?? 0);

        if (!$bookingId && !$invoiceId) {
            throw ValidationException::withMessages([
                'refund' => ['Either booking_id or invoice_id is required.'],
            ]);
        }

        $method = strtolower(str_replace(' ', '_', $data['payment_method'] ?? 'cash'));
        $allowedMethods = ['cash', 'gcash', 'maya', 'bank_transfer', 'card', 'check'];
        if (!in_array($method, $allowedMethods, true)) {
            $method = 'cash';
        }

        return DB::transaction(function () use ($data, $bookingId, $invoiceId, $amount, $method, $userId) {
            $booking = $bookingId ? Booking::with(['serviceEvent.customer'])->find($bookingId) : null;
            $invoice = $invoiceId ? Invoice::with(['booking.serviceEvent.customer'])->find($invoiceId) : null;

            $payment = null;
            if ($amount > 0 && $booking) {
                $payment = BookingPayment::create([
                    'booking_id' => $booking->booking_id,
                    'payment_number' => 'REF-' . now()->format('YmdHisv') . '-' . $booking->booking_id . '-' . random_int(100, 999),
                    'amount' => $amount,
                    'payment_method' => $method,
                    'payment_type' => 'refund',
                    'reference_number' => $data['reference_number'] ?? null,
                    'notes' => trim('Admin direct refund. ' . ($data['reason'] ?? '')),
                    'status' => 'completed',
                    'payment_date' => now(),
                    'verified_by' => $userId,
                    'verified_at' => now(),
                ]);

                $this->synchronizeInvoice($booking);
            }

            $refund = Refund::create([
                'refund_number' => Refund::generateRefundNumber(),
                'booking_id' => $bookingId,
                'invoice_id' => $invoiceId,
                'payment_id' => $payment?->payment_id,
                'requested_by' => $userId,
                'approved_by' => $userId,
                'released_by' => $userId,
                'amount' => $amount,
                'approved_amount' => $amount,
                'released_amount' => $amount,
                'status' => Refund::STATUS_RELEASED,
                'reason' => $data['reason'] ?? 'Admin direct refund',
                'admin_notes' => $data['notes'] ?? null,
                'payment_method' => $method,
                'reference_number' => $data['reference_number'] ?? null,
                'requested_at' => now(),
                'approved_at' => now(),
                'released_at' => now(),
                'source' => Refund::SOURCE_ADMIN_DIRECT,
            ]);

            // Update invoice refund status
            if ($invoiceId) {
                Invoice::where('invoice_id', $invoiceId)->update([
                    'refund_status' => Refund::STATUS_RELEASED,
                    'refund_amount' => $amount,
                    'refund_released' => $amount,
                ]);
            } elseif ($booking?->invoice) {
                $booking->invoice->update([
                    'refund_status' => Refund::STATUS_RELEASED,
                    'refund_amount' => $amount,
                    'refund_released' => $amount,
                ]);
            }

            // Cancel booking if requested
            if ($booking && ($data['cancel_booking'] ?? true)) {
                $booking->update([
                    'booking_status' => 'cancelled',
                    'cancellation_reason' => trim('Cancelled by admin with refund. ' . ($data['reason'] ?? '')),
                ]);
                $booking->serviceEvent?->update(['status' => 'cancelled']);
            }

            return $refund;
        });
    }

    /**
     * Get refund history with filters
     */
    public function getRefundHistory(array $filters = []): array
    {
        $query = Refund::with([
            'booking.serviceEvent.customer.person',
            'invoice.booking.serviceEvent.customer.person',
            'requestedBy',
            'approvedBy',
            'releasedBy',
            'rejectedBy',
        ]);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['booking_id'])) {
            $query->where('booking_id', $filters['booking_id']);
        }

        if (!empty($filters['invoice_id'])) {
            $query->where('invoice_id', $filters['invoice_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('refund_number', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhereHas('booking', fn($b) => $b->where('booking_no', 'like', "%{$search}%"))
                    ->orWhereHas('invoice', fn($i) => $i->where('invoice_number', 'like', "%{$search}%"));
            });
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        return $query->latest('refund_id')->paginate($perPage)->toArray();
    }

    /**
     * Get refund statistics
     */
    public function getRefundStatistics(): array
    {
        $total = Refund::count();
        $pending = Refund::pending()->count();
        $approved = Refund::approved()->count();
        $released = Refund::released()->count();
        $rejected = Refund::where('status', Refund::STATUS_REJECTED)->count();

        $totalReleased = (float) Refund::released()->sum('released_amount');
        $totalPending = (float) Refund::pending()->sum('deposit_snapshot');

        return [
            'total_refunds' => $total,
            'pending' => $pending,
            'approved' => $approved,
            'released' => $released,
            'rejected' => $rejected,
            'total_released_amount' => $totalReleased,
            'total_pending_amount' => $totalPending,
        ];
    }

    /**
     * Sync invoice paid amount after refund
     */
    private function synchronizeInvoice(Booking $booking): void
    {
        $booking->loadMissing('invoice');
        if (!$booking->invoice) {
            return;
        }

        $completed = (float) $booking->payments()
            ->where('status', 'completed')
            ->where('payment_type', '!=', 'refund')
            ->sum('amount');

        $refunded = (float) $booking->payments()
            ->where('status', 'completed')
            ->where('payment_type', 'refund')
            ->sum('amount');

        $netPaid = max(0, $completed - $refunded);
        $total = (float) $booking->invoice->total_amount;

        $status = 'unpaid';
        if ($netPaid >= $total && $total > 0) {
            $status = 'paid';
        } elseif ($netPaid > 0) {
            $status = 'partial';
        }

        if ($status !== 'paid' && $booking->invoice->due_date?->isPast()) {
            $status = 'overdue';
        }

        $booking->invoice->update([
            'paid_amount' => $netPaid,
            'status' => $status,
        ]);
    }
}