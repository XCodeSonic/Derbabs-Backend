<?php

namespace App\Http\Controllers\Api;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\AuditLog;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    /**
     * Display a listing of all payments with filters
     */
    public function index(Request $request)
    {
        try {
            $query = BookingPayment::with([
                'booking.serviceEvent.customer.person',
                'booking.invoice',
                'verifier.person'
            ]);

            if (! $request->boolean('include_history')) {
                $query->whereHas(
                    'booking',
                    fn($bookingQuery) =>
                    $bookingQuery->where('booking_no', 'not like', 'HIST-%')
                );
            }

            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->filled('booking_id')) {
                $query->where('booking_id', $request->input('booking_id'));
            }

            if ($request->filled('payment_method')) {
                $query->where('payment_method', $request->input('payment_method'));
            }

            if ($request->filled('payment_type')) {
                $query->where('payment_type', $request->input('payment_type'));
            }

            if ($request->filled('date_from')) {
                $query->where('payment_date', '>=', $request->date('date_from'));
            }
            if ($request->filled('date_to')) {
                $query->where('payment_date', '<=', $request->date('date_to'));
            }

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('reference_number', 'like', "%{$search}%")
                        ->orWhere('payment_number', 'like', "%{$search}%")
                        ->orWhere('account_name', 'like', "%{$search}%")
                        ->orWhereHas('booking.serviceEvent.customer.person', function ($person) use ($search) {
                            $person->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                });
            }

            $summaryQuery = clone $query;
            $payments = $query->latest('payment_id')->paginate($request->integer('per_page', 20));

            $payments->getCollection()->transform(function ($payment) {
                return $this->formatPaymentWithFullDetails($payment);
            });

            return $this->ok([
                'data' => $payments,
                'summary' => [
                    'gross_completed_amount' => (float) (clone $summaryQuery)->whereIn('status', ['completed', 'refunded'])->where('payment_type', '!=', 'refund')->sum('amount'),
                    'refunded_amount' => (float) (clone $summaryQuery)->where('status', 'completed')->where('payment_type', 'refund')->sum('amount'),
                    'net_completed_amount' => $this->netCompletedAmount(clone $summaryQuery),
                    'pending_amount' => (float) (clone $summaryQuery)->where('status', 'pending')->sum('amount'),
                    'failed_amount' => (float) (clone $summaryQuery)->where('status', 'failed')->sum('amount'),
                    'total_count' => (clone $summaryQuery)->count(),
                    'completed_count' => (clone $summaryQuery)->where('status', 'completed')->count(),
                    'pending_count' => (clone $summaryQuery)->where('status', 'pending')->count(),
                    'by_method' => (clone $summaryQuery)->select('payment_method')
                        ->selectRaw('COUNT(*) as count, SUM(amount) as total')
                        ->where('status', 'completed')
                        ->groupBy('payment_method')
                        ->get(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Payment index error: ' . $e->getMessage());
            return $this->fail('Failed to load payments: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Payment Tracking contains every payment log, regardless of verification or
     * whether the related invoice has already been settled.
     */
    public function tracking(Request $request)
    {
        return $this->index($request);
    }

    /**
     * Payment History contains one row per fully-paid invoice only.
     */
    public function history(Request $request)
    {
        try {
            $query = Invoice::with([
                'booking.serviceEvent.customer.person',
                'booking.payments' => fn($payments) => $payments->latest('payment_date'),
            ])
                ->whereHas('booking', function ($bookingQuery) use ($request) {
                    if (! $request->boolean('include_history')) {
                        $bookingQuery->where('booking_no', 'not like', 'HIST-%');
                    }
                })
                ->where('status', 'paid')
                ->where('total_amount', '>', 0)
                ->whereColumn('paid_amount', '>=', 'total_amount');

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($invoice) use ($search) {
                    $invoice->where('invoice_number', 'like', "%{$search}%")
                        ->orWhereHas('booking.serviceEvent.customer.person', function ($person) use ($search) {
                            $person->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                });
            }

            $invoices = $query->latest('invoice_id')->paginate($request->integer('per_page', 20));
            $invoices->getCollection()->transform(function (Invoice $invoice) {
                $completedPayments = $invoice->booking?->payments
                    ?->where('status', 'completed')
                    ->where('payment_type', '!=', 'refund')
                    ->values() ?? collect();
                $latestPayment = $completedPayments->sortByDesc('payment_date')->first();
                $methods = $completedPayments->pluck('payment_method')->filter()->unique()->values();
                $person = $invoice->booking?->serviceEvent?->customer?->person;

                return [
                    'id' => 'invoice-' . $invoice->invoice_id,
                    'payment_id' => $latestPayment?->payment_id,
                    'invoice_id' => $invoice->invoice_id,
                    'invoice_number' => $invoice->invoice_number,
                    'booking_id' => $invoice->booking_id,
                    'booking_no' => $invoice->booking?->booking_no ?? 'N/A',
                    'customer_name' => $person?->full_name ?? 'Unknown',
                    'customer_email' => $person?->email,
                    'amount' => (float) $invoice->total_amount,
                    'total_amount' => (float) $invoice->total_amount,
                    'paid_amount' => (float) $invoice->paid_amount,
                    'balance' => 0.0,
                    'status' => 'paid',
                    'payment_number' => $latestPayment?->payment_number ?? 'Fully Paid',
                    'payment_method' => $methods->count() > 1 ? 'multiple' : ($methods->first() ?? 'N/A'),
                    'payment_type' => 'full',
                    'reference_number' => $latestPayment?->reference_number ?? 'N/A',
                    'date' => $latestPayment?->payment_date?->toDateString() ?? $invoice->updated_at?->toDateString(),
                    'date_time' => $latestPayment?->payment_date?->toDateTimeString() ?? $invoice->updated_at?->toDateTimeString(),
                    'payment_logs_count' => $completedPayments->count(),
                    'payment_logs' => $completedPayments->map(fn($payment) => [
                        'payment_id' => $payment->payment_id,
                        'payment_number' => $payment->payment_number,
                        'amount' => (float) $payment->amount,
                        'payment_method' => $payment->payment_method,
                        'payment_type' => $payment->payment_type,
                        'reference_number' => $payment->reference_number,
                        'date' => $payment->payment_date?->toDateTimeString(),
                    ])->values(),
                ];
            });

            return $this->ok($invoices);
        } catch (\Throwable $e) {
            Log::error('Payment history error: ' . $e->getMessage());
            return $this->fail('Failed to load payment history: ' . $e->getMessage(), 500);
        }
    }

    public function show(BookingPayment $payment)
    {
        try {
            return $this->ok(
                $this->formatPaymentWithFullDetails(
                    $payment->load(['booking.serviceEvent.customer.person', 'verifier.person', 'booking.invoice'])
                )
            );
        } catch (\Exception $e) {
            Log::error('Payment show error: ' . $e->getMessage());
            return $this->fail('Failed to load payment: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Generate sequential payment number in 4-digit format (PAY-0001).
     *
     * ⭐ Used by every payment entry point: Invoice Payment,
     *    Order & Events Payment, and Mobile Payment.
     */
    private function generatePaymentNumber(): string
    {
        return $this->generateSequentialNumber('PAY-', BookingPayment::class, 'payment_number', 4);
    }

    /**
     * Generic sequential number generator.
     *
     * ⭐ Format: PREFIX-0001, PREFIX-0002, … PREFIX-9999 (then expands).
     *    The counter resets whenever the prefix changes, so PAY- and
     *    REF- keep independent sequences.
     */
    private function generateSequentialNumber(string $prefix, string $modelClass, string $column, int $padding = 4): string
    {
        try {
            if (!class_exists($modelClass)) {
                throw new \Exception("Model class {$modelClass} not found");
            }

            $instance = new $modelClass();
            $keyName = $instance->getKeyName();

            // ⭐ Include soft-deleted rows so a deleted payment number is
            //    never reused.
            $lastRecord = $modelClass::withTrashed()
                ->where($column, 'LIKE', $prefix . '%')
                ->orderBy($keyName, 'desc')
                ->first();

            $nextNumber = 1;

            if ($lastRecord && isset($lastRecord->$column)) {
                // Extract the numeric portion after the prefix.
                $numericPart = preg_replace('/[^0-9]/', '', substr($lastRecord->$column, strlen($prefix)));
                if ($numericPart !== '') {
                    $nextNumber = ((int) $numericPart) + 1;
                }
            }

            return $prefix . str_pad((string) $nextNumber, $padding, '0', STR_PAD_LEFT);
        } catch (\Exception $e) {
            Log::warning("Failed to generate sequential number for {$prefix}: " . $e->getMessage());
            // ⭐ Fallback also honours the 4-digit format.
            return $prefix . str_pad((string) random_int(1, 9999), $padding, '0', STR_PAD_LEFT);
        }
    }
    /**
     * Store a new payment
     */
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'booking_id' => ['required', 'exists:bookings,booking_id'],
                'amount' => ['required', 'numeric', 'min:0.01'],
                'payment_method' => ['required', 'in:cash,gcash,maya,bank_transfer,card,check'],
                'payment_type' => ['nullable', 'in:deposit,partial,full'],
                'reference_number' => ['nullable', 'string', 'max:100'],
                'account_name' => ['nullable', 'string', 'max:100'],
                'account_number' => ['nullable', 'string', 'max:50'],
                'transaction_id' => ['nullable', 'string', 'max:100'],
                'notes' => ['nullable', 'string'],
                'receipt_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
                'verify_immediately' => ['nullable', 'boolean'],
                'force_duplicate' => ['nullable', 'boolean'],
            ]);

            $booking = Booking::with(['invoice', 'quotation', 'items', 'mealServices'])->findOrFail($data['booking_id']);

            // ⭐ Auto-create the invoice the first time a payment is attempted.
            $invoice = $booking->invoice;
            if (! $invoice) {
                $subtotal = (float) ($booking->quotation?->total_amount ?? 0);
                if ($subtotal <= 0) {
                    $itemsTotal = (float) $booking->items->sum(
                        fn($item) => ((float) ($item->unit_price ?? 0)) * ((int) ($item->quantity ?? 1))
                    );
                    $mealsTotal = (float) $booking->mealServices->sum(
                        fn($meal) => ((int) ($meal->pax ?? 0)) * ((float) ($meal->price_per_head ?? 0))
                    );
                    $subtotal = $itemsTotal > 0 ? $itemsTotal : $mealsTotal;
                }

                // ⭐ Delegate to the model helper so the invoice-number
                //    format lives in exactly one place and does not depend
                //    on the SoftDeletes trait being present.
                $invoiceNumber = Invoice::nextInvoiceNumber();
                // ⭐ Pull additional charges + discount from the booking
                //    so the invoice total matches what the operator entered
                //    in the Create Booking → Additional Charges step.
                $additionalCharges = 0.0;
                if (Schema::hasTable('booking_charges')) {
                    $additionalCharges = (float) DB::table('booking_charges')
                        ->where('booking_id', $booking->booking_id)
                        ->where('charge_kind', 'charge')
                        ->sum('amount');
                }

                $discount = 0.0;
                if (Schema::hasTable('booking_charges')) {
                    $discount = (float) DB::table('booking_charges')
                        ->where('booking_id', $booking->booking_id)
                        ->where('charge_kind', 'discount')
                        ->sum('amount');
                }

                $totalAmount = max(0, $subtotal + $additionalCharges - $discount);

                // ⭐ Pull charges + discount from booking_charges so the
                //    invoice total matches the booking form.
                $additionalCharges = 0.0;
                if (Schema::hasTable('booking_charges')) {
                    $additionalCharges = (float) DB::table('booking_charges')
                        ->where('booking_id', $booking->booking_id)
                        ->where('charge_kind', 'charge')
                        ->sum('amount');
                }

                $discount = 0.0;
                if (Schema::hasTable('booking_charges')) {
                    $discount = (float) DB::table('booking_charges')
                        ->where('booking_id', $booking->booking_id)
                        ->where('charge_kind', 'discount')
                        ->sum('amount');
                }

                $totalAmount = max(0, $subtotal + $additionalCharges - $discount);

                $invoice = Invoice::create([
                    'invoice_number'     => $invoiceNumber,
                    'booking_id'         => $booking->booking_id,
                    'subtotal'           => round($subtotal, 2),
                    'discount'           => round($discount, 2),
                    'discount_type'      => 'fixed',
                    'additional_charges' => round($additionalCharges, 2),
                    'total_amount'       => round($totalAmount, 2),
                    'paid_amount'        => 0,
                    'status'             => 'unpaid',
                    'due_date'           => now()->addDays(30)->toDateString(),
                    'notes'              => 'Auto-created on first payment.',
                ]);
                $booking->setRelation('invoice', $invoice);
            }
            $balance = max(0, (float) $invoice->total_amount - (float) $invoice->paid_amount);
            $requiredDeposit = round((float) $invoice->total_amount * 0.30, 2);
            $isFirstPayment = ! BookingPayment::where('booking_id', $booking->booking_id)
                ->where('status', 'completed')
                ->where('payment_type', '!=', 'refund')
                ->exists();

            // ⭐ Block additional mobile payments when the invoice is settled.
            if ($balance <= 0.01 && (float) $invoice->total_amount > 0) {
                return $this->fail(
                    'This booking is already fully paid. Additional payment is not allowed.',
                    422,
                    ['code' => 'fully_paid']
                );
            }

            // ⭐ Reject any payment above the remaining balance.
            if ((float) $data['amount'] > $balance + 0.01) {
                return $this->fail(
                    'Payment amount cannot exceed the remaining balance.',
                    422,
                    [
                        'code' => 'overpayment',
                        'remaining_balance' => $balance,
                        'submitted_amount'  => (float) $data['amount'],
                    ]
                );
            }

            // ⭐ Deposit detection — more lenient so a 30% payment is
            //    always classified as a deposit, even if the amount is
            //    off by a few pesos due to rounding or manual entry.
            if ((float) $data['amount'] >= $balance) {
                $data['payment_type'] = 'full';
            } elseif (
                $isFirstPayment &&
                $requiredDeposit > 0 &&
                (float) $data['amount'] >= ($requiredDeposit - 1.00)
            ) {
                $data['payment_type'] = 'deposit';
            } else {
                $data['payment_type'] = 'partial';
            }

            $recentPayment = BookingPayment::where('booking_id', $booking->booking_id)
                ->where('status', 'completed')
                ->where('payment_type', '!=', 'refund')
                ->where('payment_date', '>=', now()->subMinutes(5))
                ->latest('payment_date')
                ->first();

            if ($recentPayment && ! $request->boolean('force_duplicate')) {
                return $this->fail(
                    'A recent payment already exists for this invoice.',
                    409,
                    [
                        'code' => 'recent_payment',
                        'payment' => [
                            'payment_id' => $recentPayment->payment_id,
                            'payment_number' => $recentPayment->payment_number,
                            'amount' => (float) $recentPayment->amount,
                            'payment_date' => $recentPayment->payment_date?->toDateTimeString(),
                        ],
                    ]
                );
            }

            $changeAmount = max(0, (float) $data['amount'] - max(0, $balance));
            if ($changeAmount > 0) {
                $data['notes'] = trim(($data['notes'] ?? '') . "\nChange due: ₱" . number_format($changeAmount, 2));
            }

            $receiptPath = $request->hasFile('receipt_file')
                ? $request->file('receipt_file')->store('receipts', 'public')
                : null;

            $verifyImmediately = $request->boolean('verify_immediately');

            $payment = DB::transaction(function () use ($booking, $data, $receiptPath, $verifyImmediately) {
                $paymentData = [
                    'payment_number' => $this->generatePaymentNumber(),
                    'booking_id' => $booking->booking_id,
                    'amount' => (float) $data['amount'],
                    'payment_method' => $data['payment_method'],
                    'payment_type' => $data['payment_type'] ?? 'partial',
                    'reference_number' => $data['reference_number'] ?? $this->generateReferenceNumber(),
                    'transaction_id' => $data['transaction_id'] ?? null,
                    'receipt_file' => $receiptPath,
                    'notes' => $data['notes'] ?? null,
                    'status' => $verifyImmediately ? 'completed' : 'pending',
                    'payment_date' => now(),
                    'verified_by' => $verifyImmediately ? auth()->id() : null,
                    'verified_at' => $verifyImmediately ? now() : null,
                ];

                if (isset($data['account_name'])) {
                    $paymentData['account_name'] = $data['account_name'];
                }
                if (isset($data['account_number'])) {
                    $paymentData['account_number'] = $data['account_number'];
                }

                return BookingPayment::create($paymentData);
            });

            // ⭐ CRITICAL FIX: Sync the invoice AFTER the transaction commits.
            //    If syncInvoicePayment throws inside the transaction, the
            //    payment row is rolled back — which is exactly what was
            //    happening before (payments vanishing from the DB).
            try {
                $this->syncInvoicePayment($booking);
            } catch (\Throwable $e) {
                Log::error('syncInvoicePayment failed after commit', [
                    'payment_id' => $payment->payment_id,
                    'booking_id' => $booking->booking_id,
                    'error'      => $e->getMessage(),
                    'trace'      => $e->getTraceAsString(),
                ]);
                // Do not rethrow — the payment is already saved. The invoice
                // will be re-synced on the next payment or manual refresh.
            }

            // ⭐ Notifications are non-critical — never let them roll back the payment.
            if ($verifyImmediately) {
                try {
                    $this->createPaymentNotification($payment);
                } catch (\Throwable $e) {
                    Log::warning('Payment notification failed (payment already saved)', [
                        'payment_id' => $payment->payment_id,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            // ⭐ Reload the invoice + booking so the response reflects the new balance.
            $booking->unsetRelation('invoice');
            $booking->load('invoice');
            $payment->refresh();
            $payment->load(['booking.invoice', 'booking.serviceEvent.customer.person', 'verifier.person']);

            return $this->ok(
                $this->formatPaymentWithFullDetails($payment),
                $verifyImmediately ? 'Payment recorded and verified successfully.' : 'Payment recorded successfully.'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('Payment store error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return $this->fail('Failed to record payment: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Generate reference number
     */
    private function generateReferenceNumber(): string
    {
        return 'REF-' . now()->format('YmdHis') . '-' . strtoupper(substr(uniqid(), -5));
    }

    public function update(Request $request, BookingPayment $payment)
    {
        try {
            $data = $request->validate([
                'amount' => ['nullable', 'numeric', 'min:0.01'],
                'payment_method' => ['nullable', 'in:cash,gcash,maya,bank_transfer,card,check'],
                'payment_type' => ['nullable', 'in:deposit,partial,full,refund'],
                'reference_number' => ['nullable', 'string', 'max:100'],
                'account_name' => ['nullable', 'string', 'max:100'],
                'account_number' => ['nullable', 'string', 'max:50'],
                'notes' => ['nullable', 'string'],
                'status' => ['nullable', 'in:pending,completed,failed,refunded'],
            ]);

            $payment->update($data);
            $this->syncInvoicePayment($payment->booking);

            return $this->ok(
                $this->formatPaymentWithFullDetails($payment->fresh()),
                'Payment updated successfully.'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('Payment update error: ' . $e->getMessage());
            return $this->fail('Failed to update payment: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(BookingPayment $payment)
    {
        try {
            $booking = $payment->booking;

            if ($payment->receipt_file && Storage::disk('public')->exists($payment->receipt_file)) {
                Storage::disk('public')->delete($payment->receipt_file);
            }

            $payment->delete();

            if ($booking) {
                $this->syncInvoicePayment($booking);
            }

            return $this->ok(null, 'Payment deleted successfully.');
        } catch (\Exception $e) {
            Log::error('Payment delete error: ' . $e->getMessage());
            return $this->fail('Failed to delete payment: ' . $e->getMessage(), 500);
        }
    }

    public function verify(Request $request, BookingPayment $payment)
    {
        try {
            $data = $request->validate([
                'notes' => ['nullable', 'string'],
                'amount_verified' => ['nullable', 'numeric', 'min:0'],
            ]);

            if ($payment->status === 'completed') {
                return $this->fail('Payment already verified.', 422);
            }

            if ($payment->payment_type === 'refund') {
                return $this->fail('Refund records are completed through the refund endpoint.', 422);
            }

            $amountToVerify = $data['amount_verified'] ?? $payment->amount;

            $payment->update([
                'status' => 'completed',
                'verified_by' => auth()->id(),
                'verified_at' => now(),
                'notes' => $data['notes'] ?? $payment->notes,
                'amount' => $amountToVerify,
            ]);

            $this->syncInvoicePayment($payment->booking);
            $this->createPaymentNotification($payment);

            return $this->ok(
                $this->formatPaymentWithFullDetails($payment->fresh()),
                'Payment verified successfully.'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('Payment verify error: ' . $e->getMessage());
            return $this->fail('Failed to verify payment: ' . $e->getMessage(), 500);
        }
    }

    public function reject(Request $request, BookingPayment $payment)
    {
        try {
            $data = $request->validate([
                'reason' => ['required', 'string'],
            ]);

            $payment->update([
                'status' => 'failed',
                'notes' => $data['reason'],
            ]);

            $this->syncInvoicePayment($payment->booking);

            return $this->ok(
                $this->formatPaymentWithFullDetails($payment->fresh()),
                'Payment rejected.'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('Payment reject error: ' . $e->getMessage());
            return $this->fail('Failed to reject payment: ' . $e->getMessage(), 500);
        }
    }

    public function refund(Request $request, BookingPayment $payment)
    {
        try {
            $data = $request->validate([
                'reason' => ['required', 'string'],
                'refund_amount' => ['nullable', 'numeric', 'min:0.01'],
            ]);

            if ($payment->payment_type === 'refund' || $payment->status !== 'completed') {
                return $this->fail('Only a completed customer payment can be refunded.', 422);
            }

            return DB::transaction(function () use ($payment, $data) {
                $reference = 'refund_of_' . $payment->payment_id;
                $alreadyRefunded = (float) BookingPayment::where('transaction_id', $reference)
                    ->where('payment_type', 'refund')
                    ->where('status', 'completed')
                    ->sum('amount');

                $remaining = max(0, (float) $payment->amount - $alreadyRefunded);
                $refundAmount = (float) ($data['refund_amount'] ?? $remaining);

                if ($refundAmount <= 0 || $refundAmount > $remaining) {
                    return $this->fail('Maximum refundable amount is ₱' . number_format($remaining, 2), 422);
                }

                $refund = BookingPayment::create([
                    'payment_number' => $this->generatePaymentNumber(),
                    'booking_id' => $payment->booking_id,
                    'amount' => $refundAmount,
                    'payment_method' => $payment->payment_method,
                    'payment_type' => 'refund',
                    'reference_number' => $payment->reference_number,
                    'transaction_id' => $reference,
                    'status' => 'completed',
                    'notes' => $data['reason'],
                    'payment_date' => now(),
                    'verified_by' => auth()->id(),
                    'verified_at' => now(),
                ]);

                if ($alreadyRefunded + $refundAmount >= (float) $payment->amount) {
                    $payment->update(['status' => 'refunded']);
                } else {
                    $payment->update([
                        'notes' => trim(($payment->notes ? $payment->notes . "\n" : '') .
                            'Partially refunded: ₱' . number_format($alreadyRefunded + $refundAmount, 2))
                    ]);
                }

                $this->syncInvoicePayment($payment->booking);

                return $this->ok(
                    $this->formatPaymentWithFullDetails($refund->fresh()),
                    'Refund recorded successfully.'
                );
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('Payment refund error: ' . $e->getMessage());
            return $this->fail('Failed to process refund: ' . $e->getMessage(), 500);
        }
    }

    public function downloadReceipt(BookingPayment $payment)
    {
        try {
            $html = $this->generateReceiptHTML($payment);
            $filename = "receipt-{$payment->payment_number}.html";

            return response($html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        } catch (\Exception $e) {
            Log::error('Download receipt error: ' . $e->getMessage());
            return $this->fail('Failed to download receipt: ' . $e->getMessage(), 500);
        }
    }

    public function getReceipt(BookingPayment $payment)
    {
        try {
            $payment->load([
                'booking.serviceEvent.customer.person',
                'booking.serviceEvent.eventType',
                'booking.invoice',
                'booking.quotation',
                'booking.items.menuItem',
                'booking.items.mealService',
                'booking.mealServices.menuItem',
                'booking.mealServices.eventDay',
                'booking.mealServices.customItems.menuItem',
                'booking.payments',
            ]);

            return $this->ok([
                'payment' => $this->formatPaymentWithFullDetails($payment),
                'receipt_html' => $this->generateReceiptHTML($payment),
            ]);
        } catch (\Exception $e) {
            Log::error('Get receipt error: ' . $e->getMessage());
            return $this->fail('Failed to load receipt: ' . $e->getMessage(), 500);
        }
    }

    public function getAllMobilePayments(Request $request)
    {
        try {
            $query = BookingPayment::with([
                'booking.serviceEvent.customer.person',
                'booking.invoice',
                'verifier.person'
            ])->whereIn('payment_method', ['gcash', 'maya', 'bank_transfer', 'card']);

            if (! $request->boolean('include_history')) {
                $query->whereHas(
                    'booking',
                    fn($bookingQuery) =>
                    $bookingQuery->where('booking_no', 'not like', 'HIST-%')
                );
            }

            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('reference_number', 'like', "%{$search}%")
                        ->orWhere('account_name', 'like', "%{$search}%")
                        ->orWhereHas('booking.serviceEvent.customer.person', function ($person) use ($search) {
                            $person->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                });
            }

            $payments = $query->latest('payment_id')->paginate($request->integer('per_page', 20));

            $payments->getCollection()->transform(function ($payment) {
                return $this->formatPaymentWithFullDetails($payment);
            });

            return $this->ok($payments);
        } catch (\Exception $e) {
            Log::error('Mobile payments error: ' . $e->getMessage());
            return $this->fail('Failed to load mobile payments: ' . $e->getMessage(), 500);
        }
    }

    public function recordMobilePayment(Request $request)
    {
        try {
            $data = $request->validate([
                'booking_id' => ['required', 'exists:bookings,booking_id'],
                'amount' => ['required', 'numeric', 'min:0.01'],
                'payment_method' => ['required', 'in:cash,gcash,maya,bank_transfer,card,check'],
                'payment_type' => ['nullable', 'in:deposit,partial,full'],
                'reference_number' => ['nullable', 'string', 'max:100'],
                'account_name' => ['nullable', 'string', 'max:100'],
                'account_number' => ['nullable', 'string', 'max:50'],
                'notes' => ['nullable', 'string'],
                'proof_of_payment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
            ]);

            $booking = Booking::with(['invoice', 'quotation', 'items', 'mealServices'])->findOrFail($data['booking_id']);

            // ⭐ Auto-create the invoice the first time a payment is attempted.
            $invoice = $booking->invoice;
            if (! $invoice) {
                $subtotal = (float) ($booking->quotation?->total_amount ?? 0);
                if ($subtotal <= 0) {
                    $itemsTotal = (float) $booking->items->sum(
                        fn($item) => ((float) ($item->unit_price ?? 0)) * ((int) ($item->quantity ?? 1))
                    );
                    $mealsTotal = (float) $booking->mealServices->sum(
                        fn($meal) => ((int) ($meal->pax ?? 0)) * ((float) ($meal->price_per_head ?? 0))
                    );
                    $subtotal = $itemsTotal > 0 ? $itemsTotal : $mealsTotal;
                }

                // ⭐ Pull additional charges + discount from the booking.
                $additionalCharges = 0.0;
                if (Schema::hasTable('booking_charges')) {
                    $additionalCharges = (float) DB::table('booking_charges')
                        ->where('booking_id', $booking->booking_id)
                        ->where('charge_kind', 'charge')
                        ->sum('amount');
                }

                $discount = 0.0;
                if (Schema::hasTable('booking_charges')) {
                    $discount = (float) DB::table('booking_charges')
                        ->where('booking_id', $booking->booking_id)
                        ->where('charge_kind', 'discount')
                        ->sum('amount');
                }

                $totalAmount = max(0, $subtotal + $additionalCharges - $discount);

                $additionalCharges = 0.0;
                if (Schema::hasTable('booking_charges')) {
                    $additionalCharges = (float) DB::table('booking_charges')
                        ->where('booking_id', $booking->booking_id)
                        ->where('charge_kind', 'charge')
                        ->sum('amount');
                }

                $discount = 0.0;
                if (Schema::hasTable('booking_charges')) {
                    $discount = (float) DB::table('booking_charges')
                        ->where('booking_id', $booking->booking_id)
                        ->where('charge_kind', 'discount')
                        ->sum('amount');
                }

                $totalAmount = max(0, $subtotal + $additionalCharges - $discount);

                $invoice = Invoice::create([
                    'invoice_number'     => Invoice::nextInvoiceNumber(),
                    'booking_id'         => $booking->booking_id,
                    'subtotal'           => round($subtotal, 2),
                    'discount'           => round($discount, 2),
                    'discount_type'      => 'fixed',
                    'additional_charges' => round($additionalCharges, 2),
                    'total_amount'       => round($totalAmount, 2),
                    'paid_amount'        => 0,
                    'status'             => 'unpaid',
                    'due_date'           => now()->addDays(30)->toDateString(),
                    'notes'              => 'Auto-created on first payment.',
                ]);
                $booking->setRelation('invoice', $invoice);
            }
            $balance = max(0, (float) $invoice->total_amount - (float) $invoice->paid_amount);
            $requiredDeposit = round((float) $invoice->total_amount * 0.30, 2);
            $isFirstPayment = ! BookingPayment::where('booking_id', $booking->booking_id)
                ->where('status', 'completed')
                ->where('payment_type', '!=', 'refund')
                ->exists();

            // ⭐ Reject overpayment on the mobile path too.
            if ((float) $data['amount'] > $balance + 0.01) {
                return $this->fail(
                    'Payment amount cannot exceed the remaining balance.',
                    422,
                    [
                        'code' => 'overpayment',
                        'remaining_balance' => $balance,
                        'submitted_amount'  => (float) $data['amount'],
                    ]
                );
            }

            // ⭐ Deposit detection — more lenient so a 30% payment is
            //    always classified as a deposit.
            if ((float) $data['amount'] >= $balance) {
                $data['payment_type'] = 'full';
            } elseif (
                $isFirstPayment &&
                $requiredDeposit > 0 &&
                (float) $data['amount'] >= ($requiredDeposit - 1.00)
            ) {
                $data['payment_type'] = 'deposit';
            } else {
                $data['payment_type'] = 'partial';
            }

            $changeAmount = max(0, (float) $data['amount'] - max(0, $balance));
            if ($changeAmount > 0) {
                $data['notes'] = trim(($data['notes'] ?? '') . "\nChange due: ₱" . number_format($changeAmount, 2));
            }

            $receiptPath = $request->hasFile('proof_of_payment')
                ? $request->file('proof_of_payment')->store('mobile-payments', 'public')
                : null;
            $payment = DB::transaction(function () use ($booking, $data, $receiptPath) {
                $paymentData = [
                    'payment_number' => $this->generatePaymentNumber(),
                    'booking_id' => $booking->booking_id,
                    'amount' => (float) $data['amount'],
                    'payment_method' => $data['payment_method'],
                    'payment_type' => $data['payment_type'] ?? 'partial',
                    'reference_number' => $data['reference_number'] ?? $this->generateReferenceNumber(),
                    'receipt_file' => $receiptPath,
                    'notes' => $data['notes'] ?? null,
                    'status' => 'pending',
                    'payment_date' => now(),
                ];

                if (isset($data['account_name'])) {
                    $paymentData['account_name'] = $data['account_name'];
                }
                if (isset($data['account_number'])) {
                    $paymentData['account_number'] = $data['account_number'];
                }

                return BookingPayment::create($paymentData);
            });

            // ⭐ Sync AFTER commit so the pending row is visible and any
            //    sync failure does NOT roll back the payment.
            try {
                $this->syncInvoicePayment($booking);
            } catch (\Throwable $e) {
                Log::error('Mobile payment sync failed after commit', [
                    'payment_id' => $payment->payment_id,
                    'error'      => $e->getMessage(),
                ]);
            }

            $booking->unsetRelation('invoice');
            $booking->load('invoice');
            $payment->refresh();

            return $this->ok(
                $this->formatPaymentWithFullDetails($payment),
                'Mobile payment recorded. Please wait for verification.'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('Mobile payment error: ' . $e->getMessage());
            return $this->fail('Failed to record mobile payment: ' . $e->getMessage(), 500);
        }
    }

    public function verifyMobilePayment(Request $request, BookingPayment $payment)
    {
        try {
            $data = $request->validate([
                'notes' => ['nullable', 'string'],
                'amount_verified' => ['nullable', 'numeric', 'min:0'],
            ]);

            if ($payment->status === 'completed') {
                return $this->fail('Payment already verified.', 422);
            }

            $amountToVerify = $data['amount_verified'] ?? $payment->amount;

            $balance = max(0, (float) $invoice->total_amount - (float) $invoice->paid_amount);
            $requiredDeposit = round((float) $invoice->total_amount * 0.30, 2);
            $isFirstPayment = ! BookingPayment::where('booking_id', $booking->booking_id)
                ->where('status', 'completed')
                ->where('payment_type', '!=', 'refund')
                ->exists();

            // ⭐ Create notification for customer
            $this->createPaymentNotification($payment);

            Log::info('Mobile payment received — invoice synced', [
                'payment_id'     => $payment->payment_id,
                'booking_id'     => $payment->booking_id,
                'amount'         => $amountToVerify,
                'invoice_balance' => $payment->booking?->invoice?->balance,
            ]);

            $payment->refresh();
            $payment->load(['booking.invoice', 'booking.serviceEvent.customer.person', 'verifier.person']);

            return $this->ok(
                $this->formatPaymentWithFullDetails($payment),
                'Mobile payment received. Balance updated.'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('Verify mobile payment error: ' . $e->getMessage());
            return $this->fail('Failed to verify mobile payment: ' . $e->getMessage(), 500);
        }
    }

    public function rejectMobilePayment(Request $request, BookingPayment $payment)
    {
        try {
            $data = $request->validate([
                'reason' => ['required', 'string'],
            ]);

            $payment->update([
                'status' => 'failed',
                'notes' => $data['reason'],
            ]);

            $this->syncInvoicePayment($payment->booking);

            return $this->ok(
                $this->formatPaymentWithFullDetails($payment->fresh()),
                'Payment rejected.'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('Reject mobile payment error: ' . $e->getMessage());
            return $this->fail('Failed to reject mobile payment: ' . $e->getMessage(), 500);
        }
    }

    public function summary(Request $request)
    {
        try {
            $year = (int) ($request->input('year') ?? now()->year);
            $month = (int) ($request->input('month') ?? now()->month);

            $start = now()->setYear($year)->setMonth($month)->startOfMonth();
            $end = $start->copy()->endOfMonth();

            $query = BookingPayment::whereBetween('payment_date', [$start, $end]);

            return $this->ok([
                'period' => [
                    'month' => $month,
                    'year' => $year,
                    'month_name' => $start->format('F'),
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                ],
                'total_amount' => $this->netCompletedAmount(clone $query),
                'total_count' => (clone $query)->count(),
                'by_method' => (clone $query)->select('payment_method')
                    ->selectRaw("SUM(CASE WHEN payment_type = 'refund' AND status = 'completed' THEN -amount WHEN payment_type != 'refund' AND status IN ('completed', 'refunded') THEN amount ELSE 0 END) total")
                    ->groupBy('payment_method')
                    ->get(),
                'daily_average' => round($this->netCompletedAmount(clone $query) / max(1, $start->daysInMonth), 2),
            ]);
        } catch (\Exception $e) {
            Log::error('Payment summary error: ' . $e->getMessage());
            return $this->fail('Failed to load payment summary: ' . $e->getMessage(), 500);
        }
    }

    // ==================== PRIVATE HELPER METHODS ====================

    /**
     * Format payment with full details
     */
    private function formatPaymentWithFullDetails(BookingPayment $payment): array
    {
        $payment->loadMissing([
            'booking.serviceEvent.customer.person',
            'booking.invoice',
            'verifier.person'
        ]);

        $booking = $payment->booking;
        $invoice = $booking?->invoice;
        $person = $booking?->serviceEvent?->customer?->person;

        return [
            'id' => $payment->payment_id,
            'payment_id' => $payment->payment_id,
            'payment_number' => $payment->payment_number ?? 'N/A',
            'booking_id' => $payment->booking_id,
            'booking_no' => $booking?->booking_no ?? 'N/A',
            'customer_id' => $booking?->serviceEvent?->customer_id,
            'customer_name' => $person?->full_name ?? 'Unknown',
            'customer_email' => $person?->email ?? 'N/A',
            'customer_phone' => $person?->phone ?? 'N/A',
            'customer_avatar' => $person?->profile_photo_url,
            'invoice_id' => $invoice?->invoice_id,
            'invoice_number' => $invoice?->invoice_number ?? 'N/A',
            'amount' => (float) ($payment->amount ?? 0),
            'payment_method' => $payment->payment_method ?? 'N/A',
            'payment_type' => $payment->payment_type ?? 'partial',
            'reference_number' => $payment->reference_number ?? 'N/A',
            'account_name' => $payment->account_name ?? null,
            'account_number' => $payment->account_number ?? null,
            'transaction_id' => $payment->transaction_id ?? null,
            'status' => $payment->status ?? 'pending',
            'date' => $payment->payment_date?->toDateString(),
            'date_time' => $payment->payment_date?->toDateTimeString(),
            'notes' => $payment->notes,
            'receipt_file' => $payment->receipt_file,
            // ⭐ Storage::disk('public')->url() returns a full URL when
            //    APP_URL is set; otherwise it returns `/storage/<path>`.
            //    The frontend's resolveBackendUrl() handles both shapes.
            'receipt_url' => $payment->receipt_file
                ? Storage::disk('public')->url($payment->receipt_file)
                : null,
            'verified_by_id' => $payment->verified_by,
            'verified_by' => $payment->verifier?->person?->full_name,
            'verified_at' => $payment->verified_at?->toDateTimeString(),
            'is_mobile_payment' => in_array($payment->payment_method, ['gcash', 'maya', 'bank_transfer', 'card']),
            'created_at' => $payment->created_at?->toDateTimeString(),
            'updated_at' => $payment->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * Sync invoice payment status
     */
    private function syncInvoicePayment(?Booking $booking): void
    {
        if (!$booking) {
            Log::warning('syncInvoicePayment called with null booking');
            return;
        }

        // ⭐ Always fetch fresh — never trust a stale relation cache.
        $booking->unsetRelation('invoice');
        $booking->load('invoice');

        // ⭐ Fallback: query directly if the relation is missing.
        $invoice = $booking->invoice
            ?: \App\Models\Invoice::where('booking_id', $booking->booking_id)->first();

        if (!$invoice) {
            Log::warning('No invoice found for booking — skipping sync', [
                'booking_id' => $booking->booking_id,
                'booking_no' => $booking->booking_no,
            ]);
            return;
        }

        $totalPaid = (float) $booking->payments()
            ->where('status', 'completed')
            ->where('payment_type', '!=', 'refund')
            ->sum('amount');

        $totalRefunded = (float) $booking->payments()
            ->where('status', 'completed')
            ->where('payment_type', 'refund')
            ->sum('amount');

        $netPaid = max(0, $totalPaid - $totalRefunded);
        $total = (float) $invoice->total_amount;

        // ⭐ Recompute status — never mark 'paid' when total is 0.
        $status = 'unpaid';
        if ($total > 0 && $netPaid >= $total - 0.01) {
            $status = 'paid';
        } elseif ($netPaid > 0) {
            $status = 'partial';
        }

        if ($status !== 'paid' && $invoice->due_date && $invoice->due_date->isPast()) {
            $status = 'overdue';
        }

        // ⭐ Skip the write if nothing actually changed.
        $currentPaid = (float) $invoice->paid_amount;
        $currentStatus = (string) $invoice->status;

        if (abs($currentPaid - $netPaid) < 0.01 && $currentStatus === $status) {
            Log::info('Invoice sync skipped — no change', [
                'invoice_id' => $invoice->invoice_id,
                'paid_amount' => $netPaid,
                'status' => $status,
            ]);
            return;
        }

        $invoice->update([
            'paid_amount' => $netPaid,
            'status'      => $status,
        ]);

        Log::info('Invoice synced', [
            'invoice_id'     => $invoice->invoice_id,
            'invoice_number' => $invoice->invoice_number,
            'booking_id'     => $booking->booking_id,
            'total_amount'   => $total,
            'net_paid'       => $netPaid,
            'balance'        => max(0, $total - $netPaid),
            'status'         => $status,
        ]);
    }

    /**
     * Create payment notification
     */
    private function createPaymentNotification(BookingPayment $payment): void
    {
        $customer = $payment->booking?->serviceEvent?->customer;

        try {
            AuditLog::log(
                $payment->status === 'completed' ? 'payment_verified' : 'payment_recorded',
                'finance',
                $payment->payment_id,
                null,
                [
                    'payment_id' => $payment->payment_id,
                    'booking_id' => $payment->booking_id,
                    'amount' => $payment->amount,
                    'status' => $payment->status,
                ]
            );
        } catch (Throwable $exception) {
            Log::warning('Payment audit log failed.', [
                'payment_id' => $payment->payment_id,
                'exception' => $exception->getMessage(),
            ]);
        }

        try {
            $notificationService = app(NotificationService::class);

            if ($customer?->user_id) {
                $notificationService->notifyUser(
                    $customer->user_id,
                    'payment_verified',
                    '💳 Payment Verification',
                    'Your payment of ₱' . number_format((float) $payment->amount, 2) . ' for booking #' . $payment->booking?->booking_no . ' has been verified.',
                    Notification::PRIORITY_HIGH,
                    [
                        'payment_id' => $payment->payment_id,
                        'booking_id' => $payment->booking_id,
                        'amount' => $payment->amount,
                        'reference_id' => $payment->payment_id,
                    ],
                    '/billing'
                );
            }

            $notificationService->notifySystemEvent(
                $payment->status === 'completed' ? 'payment_verification' : 'partial_payment_received',
                'Payment of ₱' . number_format((float) $payment->amount, 2) . ' was recorded for booking #' . $payment->booking?->booking_no . '.',
                [
                    'payment_id' => $payment->payment_id,
                    'booking_id' => $payment->booking_id,
                    'amount' => $payment->amount,
                    'reference_id' => $payment->payment_id,
                ],
                ['admin']
            );
        } catch (Throwable $exception) {
            Log::warning('Payment was recorded, but its notification could not be saved.', [
                'payment_id' => $payment->payment_id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Generate receipt PDF
     */
    private function generateReceiptPDF(BookingPayment $payment)
    {
        return $this->generateReceiptHTML($payment);
    }

    /**
     * Generate receipt HTML
     */
    private function generateReceiptHTML(BookingPayment $payment): string
    {
        $payment->loadMissing([
            'booking.serviceEvent.customer.person',
            'booking.serviceEvent.eventType',
            'booking.invoice',
            'booking.quotation',
            'booking.items.menuItem',
            'booking.items.mealService',
            'booking.mealServices.menuItem',
            'booking.mealServices.eventDay',
            'booking.mealServices.customItems.menuItem',
            'booking.payments',
        ]);

        $booking = $payment->booking;
        $event = $booking?->serviceEvent;
        $person = $event?->customer?->person;
        $invoice = $booking?->invoice;
        $quotation = $booking?->quotation;

        $money = fn($value) => '₱' . number_format((float) ($value ?? 0), 2);
        $safe = fn($value) => e((string) ($value ?? 'N/A'));

        $customerName = $person?->full_name ?? trim(($person?->first_name ?? '') . ' ' . ($person?->last_name ?? '')) ?: 'Unknown';
        $eventName = $event?->eventType?->name ?? 'Event';
        $bookingNo = $booking?->booking_no ?? 'N/A';
        $paymentDate = $payment->payment_date?->format('F d, Y h:i A') ?? optional($payment->created_at)->format('F d, Y h:i A') ?? 'N/A';
        $generatedDate = now()->format('F d, Y h:i A');

        $mealRows = collect();
        if ($booking && $booking->relationLoaded('mealServices') && $booking->mealServices->isNotEmpty()) {
            foreach ($booking->mealServices as $meal) {
                $customItems = ($meal->relationLoaded('customItems') ? $meal->customItems : collect());
                if ($customItems->isNotEmpty()) {
                    foreach ($customItems as $item) {
                        $mealRows->push([
                            'day' => (int) ($meal->day_number ?? 1),
                            'date' => $meal->service_date?->toDateString(),
                            'meal_type' => $meal->meal_type ?? 'Meal',
                            'item' => $item->item_name ?? $item->menuItem?->name ?? 'Menu Item',
                            'quantity' => (int) ($item->quantity ?? 1),
                            'unit_price' => (float) ($item->unit_price ?? 0),
                            'subtotal' => (float) (($item->quantity ?? 1) * ($item->unit_price ?? 0)),
                        ]);
                    }
                } else {
                    $mealRows->push([
                        'day' => (int) ($meal->day_number ?? 1),
                        'date' => $meal->service_date?->toDateString(),
                        'meal_type' => $meal->meal_type ?? 'Meal',
                        'item' => $meal->menu_name ?? $meal->menuItem?->name ?? 'Meal Service',
                        'quantity' => (int) ($meal->pax ?? 1),
                        'unit_price' => (float) ($meal->price_per_head ?? 0),
                        'subtotal' => (float) ($meal->total_meal_amount ?? (($meal->pax ?? 1) * ($meal->price_per_head ?? 0))),
                    ]);
                }
            }
        } elseif ($booking && $booking->relationLoaded('items')) {
            foreach ($booking->items as $item) {
                $mealRows->push([
                    'day' => (int) ($item->mealService?->day_number ?? 1),
                    'date' => $item->mealService?->service_date?->toDateString(),
                    'meal_type' => $item->mealService?->meal_type ?? 'Selected Menu',
                    'item' => $item->menuItem?->name ?? 'Menu Item',
                    'quantity' => (int) ($item->quantity ?? 1),
                    'unit_price' => (float) ($item->price ?? $item->unit_price ?? 0),
                    'subtotal' => (float) ($item->total_price ?? (($item->quantity ?? 1) * ($item->price ?? $item->unit_price ?? 0))),
                ]);
            }
        }

        $mealOrder = ['Breakfast', 'Morning Snacks', 'Lunch', 'Afternoon Snacks', 'Dinner', 'Snacks', 'Selected Menu', 'Meal'];
        $mealsHtml = '';
        if ($mealRows->isEmpty()) {
            $mealsHtml = '<p class="muted">No menu item records were attached to this payment.</p>';
        } else {
            foreach ($mealRows->groupBy('day')->sortKeys() as $day => $dayRows) {
                $dayTotal = $dayRows->sum('subtotal');
                $dayDate = $dayRows->first()['date'] ?? null;
                $mealsHtml .= '<div class="day-block"><h3>Day ' . e($day) . ($dayDate ? ' - ' . e($dayDate) : '') . '</h3>';
                foreach ($dayRows->groupBy('meal_type')->sortBy(fn($rows, $mealType) => array_search($mealType, $mealOrder, true) === false ? 99 : array_search($mealType, $mealOrder, true)) as $mealType => $rows) {
                    $subtotal = $rows->sum('subtotal');
                    $mealsHtml .= '<h4>' . e($mealType) . ' <span>' . $money($subtotal) . '</span></h4><table><thead><tr><th>Menu Item</th><th>Qty/PAX</th><th>Unit Price</th><th>Subtotal</th></tr></thead><tbody>';
                    foreach ($rows as $row) {
                        $mealsHtml .= '<tr><td>' . e($row['item']) . '</td><td>' . e($row['quantity']) . '</td><td>' . $money($row['unit_price']) . '</td><td>' . $money($row['subtotal']) . '</td></tr>';
                    }
                    $mealsHtml .= '</tbody></table>';
                }
                $mealsHtml .= '<div class="day-total">Day Total: <strong>' . $money($dayTotal) . '</strong></div></div>';
            }
        }

        $additionalRows = [
            'Transportation Fee' => $invoice?->transportation_fee ?? $booking?->transportation_fee ?? 0,
            'Setup Fee' => $booking?->setup_fee ?? 0,
            'Service Crew Fee' => $booking?->service_crew_fee ?? 0,
            'Equipment Rental' => $booking?->equipment_rental ?? 0,
            'Extra Food Fee' => $booking?->extra_food_fee ?? 0,
            'Discount' => -abs((float) ($invoice?->discount_amount ?? $booking?->discount ?? 0)),
        ];
        $chargesHtml = '';
        foreach ($additionalRows as $label => $value) {
            if ((float) $value != 0.0) {
                $chargesHtml .= '<tr><td>' . e($label) . '</td><td>' . $money($value) . '</td></tr>';
            }
        }
        if ($chargesHtml === '') {
            $chargesHtml = '<tr><td colspan="2" class="muted">No additional charges or adjustments recorded.</td></tr>';
        }

        $paymentsHtml = '';
        $allPayments = $booking?->payments ?? collect([$payment]);
        foreach ($allPayments as $row) {
            $paymentsHtml .= '<tr><td>' . e($row->payment_number ?? '-') . '</td><td>' . e($row->payment_method ?? '-') . '</td><td>' . e($row->payment_type ?? '-') . '</td><td>' . $money($row->amount ?? 0) . '</td><td>' . e($row->status ?? '-') . '</td></tr>';
        }

        $subtotal = (float) ($mealRows->sum('subtotal') ?: ($invoice?->subtotal ?? $quotation?->total_amount ?? 0));
        $grandTotal = (float) ($invoice?->total_amount ?? $quotation?->total_amount ?? $subtotal);
        $paidAmount = (float) ($invoice?->paid_amount ?? $allPayments->where('status', 'completed')->sum('amount'));
        $balance = max(0, $grandTotal - $paidAmount);

        $accountRows = '';
        if ($payment->account_name) {
            $accountRows .= '<tr><td>Account Name</td><td>' . $safe($payment->account_name) . '</td></tr>';
        }
        if ($payment->account_number) {
            $accountRows .= '<tr><td>Account Number</td><td>' . $safe($payment->account_number) . '</td></tr>';
        }
        $notes = $payment->notes ? '<div class="notes"><strong>Notes:</strong><br>' . nl2br(e($payment->notes)) . '</div>' : '';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Payment Receipt - {$safe($payment->payment_number)}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #111827; }
        .receipt-container { max-width: 980px; margin: 0 auto; background: #fff; }
        .receipt-header { text-align: center; border-bottom: 3px solid #1a7ab5; padding-bottom: 16px; margin-bottom: 18px; }
        h1 { color: #1a7ab5; margin: 0 0 6px; }
        h2 { margin-top: 22px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 6px; }
        h3 { background: #f3f4f6; padding: 8px; margin: 16px 0 8px; }
        h4 { display: flex; justify-content: space-between; margin: 12px 0 6px; color: #374151; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 12px; }
        th, td { border: 1px solid #d1d5db; padding: 7px 8px; text-align: left; }
        th { background: #f9fafb; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .summary { background: #eff6ff; padding: 12px; border-radius: 6px; margin-top: 12px; }
        .summary-row, .day-total { display: flex; justify-content: space-between; padding: 4px 0; }
        .grand { font-size: 18px; font-weight: bold; color: #1a7ab5; border-top: 1px solid #bfdbfe; margin-top: 6px; padding-top: 8px; }
        .muted { color: #6b7280; }
        .notes { margin-top: 14px; padding: 10px; background: #f9fafb; border-left: 4px solid #1a7ab5; }
        .footer { text-align: center; color: #6b7280; font-size: 12px; margin-top: 24px; border-top: 1px solid #e5e7eb; padding-top: 12px; }
        @media print { body { margin: 10px; } .receipt-container { max-width: none; } }
    </style>
</head>
<body>
<div class="receipt-container">
    <div class="receipt-header">
        <h1>PAYMENT RECEIPT</h1>
        <div>Receipt #: <strong>{$safe($payment->payment_number)}</strong></div>
        <div>Date: <strong>{$safe($paymentDate)}</strong></div>
    </div>

    <div class="grid">
        <div>
            <h2>Customer Information</h2>
            <table>
                <tr><td>Customer</td><td><strong>{$safe($customerName)}</strong></td></tr>
                <tr><td>Email</td><td>{$safe($person?->email ?? 'N/A')}</td></tr>
                <tr><td>Phone</td><td>{$safe($person?->phone ?? 'N/A')}</td></tr>
            </table>
        </div>
        <div>
            <h2>Event Information</h2>
            <table>
                <tr><td>Booking #</td><td><strong>{$safe($bookingNo)}</strong></td></tr>
                <tr><td>Event</td><td>{$safe($eventName)}</td></tr>
                <tr><td>Date</td><td>{$safe(optional($event?->event_date)->format('Y-m-d'))}</td></tr>
                <tr><td>Time</td><td>{$safe($event?->event_time)}</td></tr>
                <tr><td>Venue</td><td>{$safe($event?->venue)}</td></tr>
                <tr><td>Guests</td><td>{$safe($event?->guests_count ?? 0)} PAX</td></tr>
            </table>
        </div>
    </div>

    <h2>Selected Menu Items</h2>
    {$mealsHtml}

    <h2>Additional Charges / Adjustments</h2>
    <table><tbody>{$chargesHtml}</tbody></table>

    <h2>Payment Summary</h2>
    <table>
        <tr><td>Payment Method</td><td><strong>{$safe(strtoupper($payment->payment_method ?? 'N/A'))}</strong></td></tr>
        <tr><td>Payment Type</td><td>{$safe(strtoupper($payment->payment_type ?? 'partial'))}</td></tr>
        <tr><td>Reference #</td><td>{$safe($payment->reference_number ?? 'N/A')}</td></tr>
        <tr><td>Status</td><td>{$safe(strtoupper($payment->status ?? 'pending'))}</td></tr>
        {$accountRows}
    </table>
    <table>
        <thead><tr><th>Payment #</th><th>Method</th><th>Type</th><th>Amount</th><th>Status</th></tr></thead>
        <tbody>{$paymentsHtml}</tbody>
    </table>

    <div class="summary">
        <div class="summary-row"><span>Menu Subtotal</span><strong>{$money($subtotal)}</strong></div>
        <div class="summary-row grand"><span>Grand Total</span><strong>{$money($grandTotal)}</strong></div>
        <div class="summary-row"><span>Total Paid</span><strong>{$money($paidAmount)}</strong></div>
        <div class="summary-row"><span>Balance</span><strong>{$money($balance)}</strong></div>
        <div class="summary-row"><span>This Payment</span><strong>{$money($payment->amount ?? 0)}</strong></div>
    </div>

    {$notes}

    <div class="footer">
        <p>Thank you for your business. This is a system-generated receipt.</p>
        <p>Generated on: {$safe($generatedDate)}</p>
    </div>
</div>
</body>
</html>
HTML;
    }

    /**
     * Calculate net completed amount
     */
    private function netCompletedAmount(Builder $query): float
    {
        return (float) $query->selectRaw("
            COALESCE(SUM(
                CASE 
                    WHEN payment_type = 'refund' AND status = 'completed' THEN -amount 
                    WHEN payment_type != 'refund' AND status IN ('completed', 'refunded') THEN amount 
                    ELSE 0 
                END
            ), 0) total
        ")->value('total');
    }
}
