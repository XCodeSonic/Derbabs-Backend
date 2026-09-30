<?php

namespace App\Http\Controllers\Api;

use App\Models\Refund;
use App\Services\RefundService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RefundController extends Controller
{
    protected RefundService $refundService;

    public function __construct(RefundService $refundService)
    {
        $this->refundService = $refundService;
    }

    /**
     * List all refunds with filters
     */
    public function index(Request $request)
    {
        try {
            $filters = $request->only([
                'status', 'booking_id', 'invoice_id', 'date_from', 'date_to', 'search', 'per_page'
            ]);
            $refunds = $this->refundService->getRefundHistory($filters);
            return $this->ok($refunds);
        } catch (\Exception $e) {
            Log::error('Refund index error: ' . $e->getMessage());
            return $this->fail('Failed to load refunds: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get refund statistics
     */
    public function statistics()
    {
        try {
            return $this->ok($this->refundService->getRefundStatistics());
        } catch (\Exception $e) {
            Log::error('Refund statistics error: ' . $e->getMessage());
            return $this->fail('Failed to load statistics: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Show a single refund
     */
    public function show(Refund $refund)
    {
        try {
            $refund->load([
                'booking.serviceEvent.customer.person',
                'invoice.booking.serviceEvent.customer.person',
                'requestedBy', 'approvedBy', 'releasedBy', 'rejectedBy',
            ]);
            return $this->ok($refund);
        } catch (\Exception $e) {
            Log::error('Refund show error: ' . $e->getMessage());
            return $this->fail('Failed to load refund: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Submit a new refund request
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'booking_id' => ['nullable', 'exists:bookings,booking_id'],
                'invoice_id' => ['nullable', 'exists:invoices,invoice_id'],
                'reason' => ['required', 'string', 'max:1000'],
            ]);

            $refund = $this->refundService->requestRefund(
                $validated,
                optional($request->user())->user_id
            );

            return $this->ok($refund, 'Refund request submitted successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('Refund store error: ' . $e->getMessage());
            return $this->fail('Failed to submit refund: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Approve a pending refund
     */
    public function approve(Request $request, Refund $refund)
    {
        try {
            $validated = $request->validate([
                'approved_amount' => ['required', 'numeric', 'min:0'],
                'admin_notes' => ['nullable', 'string', 'max:1000'],
            ]);

            $refund = $this->refundService->approveRefund(
                $refund,
                $validated,
                optional($request->user())->user_id
            );

            return $this->ok($refund, 'Refund approved successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('Refund approve error: ' . $e->getMessage());
            return $this->fail('Failed to approve refund: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Release an approved refund
     */
    public function release(Request $request, Refund $refund)
    {
        try {
            $validated = $request->validate([
                'released_amount' => ['required', 'numeric', 'min:0.01'],
                'payment_method' => ['nullable', 'string', 'max:50'],
                'reference_number' => ['nullable', 'string', 'max:100'],
                'notes' => ['nullable', 'string', 'max:500'],
                'cancel_booking' => ['nullable', 'boolean'],
            ]);

            $refund = $this->refundService->releaseRefund(
                $refund,
                $validated,
                optional($request->user())->user_id
            );

            return $this->ok($refund, 'Refund released successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('Refund release error: ' . $e->getMessage());
            return $this->fail('Failed to release refund: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Reject a pending refund
     */
    public function reject(Request $request, Refund $refund)
    {
        try {
            $validated = $request->validate([
                'reason' => ['required', 'string', 'max:500'],
            ]);

            $refund = $this->refundService->rejectRefund(
                $refund,
                $validated['reason'],
                optional($request->user())->user_id
            );

            return $this->ok($refund, 'Refund rejected.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('Refund reject error: ' . $e->getMessage());
            return $this->fail('Failed to reject refund: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Admin direct refund (bypass approval flow)
     */
    public function adminDirect(Request $request)
    {
        try {
            $validated = $request->validate([
                'booking_id' => ['nullable', 'exists:bookings,booking_id'],
                'invoice_id' => ['nullable', 'exists:invoices,invoice_id'],
                'amount' => ['required', 'numeric', 'min:0'],
                'payment_method' => ['nullable', 'string', 'max:50'],
                'reference_number' => ['nullable', 'string', 'max:100'],
                'reason' => ['nullable', 'string', 'max:500'],
                'notes' => ['nullable', 'string', 'max:500'],
                'cancel_booking' => ['nullable', 'boolean'],
            ]);

            $refund = $this->refundService->adminDirectRefund(
                $validated,
                optional($request->user())->user_id
            );

            return $this->ok($refund, 'Refund processed successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('Admin direct refund error: ' . $e->getMessage());
            return $this->fail('Failed to process refund: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get refunds by booking
     */
    public function byBooking(int $bookingId)
    {
        try {
            $refunds = Refund::with(['requestedBy', 'approvedBy', 'releasedBy'])
                ->where('booking_id', $bookingId)
                ->latest('refund_id')
                ->get();
            return $this->ok($refunds);
        } catch (\Exception $e) {
            Log::error('Refunds by booking error: ' . $e->getMessage());
            return $this->fail('Failed to load refunds: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get refunds by invoice
     */
    public function byInvoice(int $invoiceId)
    {
        try {
            $refunds = Refund::with(['requestedBy', 'approvedBy', 'releasedBy'])
                ->where('invoice_id', $invoiceId)
                ->latest('refund_id')
                ->get();
            return $this->ok($refunds);
        } catch (\Exception $e) {
            Log::error('Refunds by invoice error: ' . $e->getMessage());
            return $this->fail('Failed to load refunds: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get pending refunds for admin dashboard
     */
    public function pending()
    {
        try {
            $refunds = Refund::with([
                'booking.serviceEvent.customer.person',
                'invoice.booking.serviceEvent.customer.person',
                'requestedBy',
            ])
                ->where('status', Refund::STATUS_PENDING)
                ->latest('requested_at')
                ->get();
            return $this->ok($refunds);
        } catch (\Exception $e) {
            Log::error('Pending refunds error: ' . $e->getMessage());
            return $this->fail('Failed to load pending refunds: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get approved refunds awaiting release (for cashier)
     */
    public function approved()
    {
        try {
            $refunds = Refund::with([
                'booking.serviceEvent.customer.person',
                'invoice.booking.serviceEvent.customer.person',
                'requestedBy',
                'approvedBy',
            ])
                ->where('status', Refund::STATUS_APPROVED)
                ->latest('approved_at')
                ->get();
            return $this->ok($refunds);
        } catch (\Exception $e) {
            Log::error('Approved refunds error: ' . $e->getMessage());
            return $this->fail('Failed to load approved refunds: ' . $e->getMessage(), 500);
        }
    }
}