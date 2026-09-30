<?php

namespace App\Http\Controllers\Api;

use App\Models\Booking;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Invoice;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class InvoiceController extends Controller
{
    private function resolveBillingPeriod(?string $period, ?string $anchor): array
    {
        $period     = strtolower((string) ($period ?: 'monthly'));
        $anchorDate = $anchor ? Carbon::parse($anchor) : now();

        switch ($period) {
            case 'weekly':
                return [
                    $anchorDate->copy()->startOfWeek(Carbon::MONDAY),
                    $anchorDate->copy()->endOfWeek(Carbon::SUNDAY),
                ];
            case 'yearly':
                return [
                    $anchorDate->copy()->startOfYear(),
                    $anchorDate->copy()->endOfYear(),
                ];
            case 'all':
                return [null, null];
            case 'monthly':
            default:
                return [
                    $anchorDate->copy()->startOfMonth(),
                    $anchorDate->copy()->endOfMonth(),
                ];
        }
    }

    public function index(Request $request)
    {
        try {
            $query = Invoice::with([
                'booking.serviceEvent.customer.person',
                'booking.serviceEvent.eventType',
                'booking.payments',
            ]);

            // ⭐ TEMP DEBUG — remove after the invoice table is verified.
            \Log::info('Invoices index called', [
                'user_id'   => $request->user()?->user_id,
                'roles'     => $request->user()?->roles?->pluck('slug')->all(),
                'params'    => $request->only([
                    'per_page',
                    'include_history',
                    'include_paid',
                    'status',
                    'search',
                    'booking_id',
                ]),
                'total_in_invoices_table' => Invoice::count(),
                'total_with_balance'      => Invoice::whereColumn('paid_amount', '<', 'total_amount')->count(),
            ]);

            $period = $request->input('period', 'monthly');
            $anchor = $request->input('anchor', now()->toDateString());
            [$start, $end] = $this->resolveBillingPeriod($period, $anchor);

            if ($start && $end) {
                $query->whereHas('booking.serviceEvent', function ($q) use ($start, $end) {
                    $q->whereDate('event_date', '>=', $start->toDateString())
                        ->whereDate('event_date', '<=', $end->toDateString());
                });
            }

            if (! $request->boolean('include_history')) {
                $query->whereHas('booking', fn($q) => $q->where('booking_no', 'not like', 'HIST-%'));
            }

            // ⭐ RULE #1 + #2:
            //    – Cancelled invoices are always excluded.
            //    – Unless `include_paid=1` is explicitly requested (for
            //      reports and PDF Overview), fully-paid invoices are
            //      excluded — they belong in Payment History instead.
            $query->where('status', '!=', 'cancelled');

            if (! $request->boolean('include_paid')) {
                $query->whereColumn('paid_amount', '<', 'total_amount');
            }

            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->filled('booking_id')) {
                $query->where('booking_id', $request->input('booking_id'));
            }

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('invoice_number', 'like', "%{$search}%")
                        ->orWhereHas('booking.serviceEvent.customer.person', function ($person) use ($search) {
                            $person->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                });
            }

            $perPage = min(500, max(1, $request->integer('per_page', 20)));
            $rows = $query->latest('invoice_id')->paginate($perPage);

            // ⭐ Eager-load every relation the formatter needs so we don't
            //    trigger N+1 queries and so missing relations resolve to
            //    empty collections instead of throwing.
            $rows->getCollection()->transform(function ($invoice) {
                return $this->formatInvoice($invoice);
            });

            return $this->ok($rows)
                ->header('Cache-Control', 'private, max-age=60');
        } catch (\Exception $e) {
            Log::error('Invoice index error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->fail('Failed to load invoices: ' . $e->getMessage(), 500);
        }
    }

    public function show(Invoice $invoice)
    {
        try {
            $invoice->load([
                'booking.serviceEvent.customer.person',
                'booking.serviceEvent.eventType',
                'booking.items.menuItem',
                'booking.payments',
            ]);
            return $this->ok($this->formatInvoice($invoice));
        } catch (\Exception $e) {
            Log::error('Invoice show error: ' . $e->getMessage(), [
                'invoice_id' => $invoice->invoice_id ?? null,
                'trace'      => $e->getTraceAsString(),
            ]);
            return $this->fail('Failed to load invoice: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'booking_id'         => ['required', 'exists:bookings,booking_id'],
                'subtotal'           => ['nullable', 'numeric', 'min:0'],
                'discount'           => ['nullable', 'numeric', 'min:0'],
                'discount_type'      => ['nullable', 'in:fixed,percentage'],
                'additional_charges' => ['nullable', 'numeric', 'min:0'],
                'total_amount'       => ['nullable', 'numeric', 'min:0'],
                'due_date'           => ['nullable', 'date'],
                'notes'              => ['nullable', 'string'],
                // ⭐ Per-fee line items sent from the Create Invoice modal.
                'charges'                    => ['nullable', 'array'],
                'charges.*.charge_kind'      => ['required_with:charges', 'in:charge,discount'],
                'charges.*.charge_type'      => ['required_with:charges', 'string', 'max:60'],
                'charges.*.description'      => ['nullable', 'string', 'max:255'],
                'charges.*.amount'           => ['required_with:charges', 'numeric', 'min:0'],
            ]);

            $booking = Booking::with([
                'serviceEvent.customer.person',
                'serviceEvent.eventType',
                'items.menuItem',
                'quotation',
            ])->findOrFail($data['booking_id']);

            // ⭐ Query the DB directly — do NOT trust the Eloquent relation,
            //    which can be stale in memory after a prior service call.
            $existingInvoice = Invoice::query()
                ->where('booking_id', $booking->booking_id)
                ->first();

            if ($existingInvoice) {
                return $this->fail(
                    'Invoice already exists for this booking: ' . $existingInvoice->invoice_number,
                    422
                );
            }

            $user = $request->user();
            $isCashier = $user?->hasAnyRole(['cashier', 'finance', 'finance-staff', 'finance_staff']) ?? false;
            $isAdministrator = $user?->hasAnyRole(['admin', 'administrator', 'owner', 'super-admin', 'super_admin', 'superadmin']) ?? false;

            if ($isCashier && ! $isAdministrator && ! in_array($booking->booking_status, ['confirmed', 'approved'], true)) {
                return $this->fail('Cashiers may generate invoices only for confirmed bookings.', 403);
            }

            $approvedSubtotal = $booking->quotation?->total_amount ?? $this->calculateSubtotalFromBooking($booking);
            $subtotal = ($isCashier && ! $isAdministrator) ? $approvedSubtotal : ($data['subtotal'] ?? $approvedSubtotal);
            $discount = ($isCashier && ! $isAdministrator) ? 0 : ($data['discount'] ?? 0);
            $discountType = ($isCashier && ! $isAdministrator) ? 'fixed' : ($data['discount_type'] ?? 'fixed');
            $additionalCharges = ($isCashier && ! $isAdministrator) ? 0 : ($data['additional_charges'] ?? 0);

            $totalAmount = $this->calculateTotalAmount($subtotal, $discount, $discountType, $additionalCharges);

            $invoice = DB::transaction(function () use ($booking, $data, $subtotal, $discount, $discountType, $additionalCharges, $totalAmount) {
                $invoice = Invoice::create([
                    'invoice_number'     => $this->generateInvoiceNumber(),
                    'booking_id'         => $booking->booking_id,
                    'subtotal'           => $subtotal,
                    'discount'           => $discount,
                    'discount_type'      => $discountType,
                    'additional_charges' => $additionalCharges,
                    'total_amount'       => $totalAmount,
                    'paid_amount'        => 0,
                    'status'             => 'unpaid',
                    'due_date'           => $data['due_date'] ?? now()->addDays(30)->toDateString(),
                    'notes'              => $data['notes'] ?? null,
                ]);

                // ⭐ Persist the per-fee line items into booking_charges
                //    so they show up in the Invoice Details modal and
                //    stay in sync with the invoice total.
                if (!empty($data['charges']) && Schema::hasTable('booking_charges')) {
                    // Replace any pre-existing charges for this booking.
                    \App\Models\BookingCharge::where('booking_id', $booking->booking_id)->delete();

                    $rows = [];
                    foreach ($data['charges'] as $charge) {
                        $amount = (float) ($charge['amount'] ?? 0);
                        if ($amount <= 0) continue;

                        $rows[] = [
                            'booking_id'  => $booking->booking_id,
                            'charge_kind' => $charge['charge_kind'] ?? 'charge',
                            'charge_type' => $charge['charge_type'],
                            'description' => $charge['description'] ?? null,
                            'amount'      => $amount,
                            'created_at'  => now(),
                            'updated_at'  => now(),
                        ];
                    }
                    if (!empty($rows)) {
                        \App\Models\BookingCharge::insert($rows);
                    }
                }

                return $invoice;
            });

            return $this->ok(
                $this->formatInvoice($invoice->fresh('booking')),
                'Invoice created successfully.'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('Invoice store error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->fail('Failed to create invoice: ' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, Invoice $invoice)
    {
        try {
            $user = $request->user();
            $isCashier = $user?->hasAnyRole(['cashier', 'finance', 'finance-staff', 'finance_staff']) ?? false;
            $isAdministrator = $user?->hasAnyRole(['admin', 'administrator', 'owner', 'super-admin', 'super_admin', 'superadmin']) ?? false;

            if ($isCashier && ! $isAdministrator && $request->hasAny([
                'subtotal',
                'discount',
                'discount_type',
                'additional_charges',
                'status',
            ])) {
                return $this->fail('Pricing and invoice status adjustments require administrator approval.', 403);
            }

            $data = $request->validate([
                'subtotal'           => ['nullable', 'numeric', 'min:0'],
                'discount'           => ['nullable', 'numeric', 'min:0'],
                'discount_type'      => ['nullable', 'in:fixed,percentage'],
                'additional_charges' => ['nullable', 'numeric', 'min:0'],
                'due_date'           => ['nullable', 'date'],
                'status'             => ['nullable', 'in:unpaid,partial,paid,overdue,cancelled'],
                'notes'              => ['nullable', 'string'],
                // ⭐ Per-fee line items sent from the Edit Invoice modal.
                'charges'                    => ['nullable', 'array'],
                'charges.*.charge_kind'      => ['required_with:charges', 'in:charge,discount'],
                'charges.*.charge_type'      => ['required_with:charges', 'string', 'max:60'],
                'charges.*.description'      => ['nullable', 'string', 'max:255'],
                'charges.*.amount'           => ['required_with:charges', 'numeric', 'min:0'],
            ]);

            $subtotal = $data['subtotal'] ?? $invoice->subtotal;
            $discount = $data['discount'] ?? $invoice->discount;
            $discountType = $data['discount_type'] ?? $invoice->discount_type ?? 'fixed';
            $additionalCharges = $data['additional_charges'] ?? $invoice->additional_charges;

            $totalAmount = $this->calculateTotalAmount($subtotal, $discount, $discountType, $additionalCharges);

            DB::transaction(function () use ($invoice, $data, $subtotal, $discount, $discountType, $additionalCharges, $totalAmount) {
                $invoice->update([
                    'subtotal'           => $subtotal,
                    'discount'           => $discount,
                    'discount_type'      => $discountType,
                    'additional_charges' => $additionalCharges,
                    'total_amount'       => $totalAmount,
                    'due_date'           => $data['due_date'] ?? $invoice->due_date,
                    'status'             => $data['status'] ?? $invoice->status,
                    'notes'              => $data['notes'] ?? $invoice->notes,
                ]);

                // ⭐ Re-sync the per-fee line items when the Edit Invoice
                //    modal sends a fresh charges array.
                if (!empty($data['charges']) && Schema::hasTable('booking_charges')) {
                    \App\Models\BookingCharge::where('booking_id', $invoice->booking_id)->delete();

                    $rows = [];
                    foreach ($data['charges'] as $charge) {
                        $amount = (float) ($charge['amount'] ?? 0);
                        if ($amount <= 0) continue;

                        $rows[] = [
                            'booking_id'  => $invoice->booking_id,
                            'charge_kind' => $charge['charge_kind'] ?? 'charge',
                            'charge_type' => $charge['charge_type'],
                            'description' => $charge['description'] ?? null,
                            'amount'      => $amount,
                            'created_at'  => now(),
                            'updated_at'  => now(),
                        ];
                    }
                    if (!empty($rows)) {
                        \App\Models\BookingCharge::insert($rows);
                    }
                }
            });

            return $this->ok(
                $this->formatInvoice($invoice->fresh('booking')),
                'Invoice updated successfully.'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('Invoice update error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->fail('Failed to update invoice: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(Invoice $invoice)
    {
        try {
            $invoice->delete();
            return $this->ok(null, 'Invoice deleted successfully.');
        } catch (\Exception $e) {
            Log::error('Invoice delete error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->fail('Failed to delete invoice: ' . $e->getMessage(), 500);
        }
    }

    public function getConfirmedBookings(Request $request)
    {
        try {
            $bookings = Booking::with([
                'serviceEvent.customer.person',
                'serviceEvent.eventType',
                'items.menuItem',
                'quotation',
                'invoice',
            ])
                ->whereIn('booking_status', ['confirmed', 'approved'])
                ->where('booking_no', 'not like', 'HIST-%')
                // ⭐ Only list bookings that do NOT yet have an invoice —
                //    this is exactly what the Create Invoice dropdown needs.
                ->whereDoesntHave('invoice')
                ->latest('booking_id')
                ->paginate(min(500, max(1, $request->integer('per_page', 200))));
            $bookings->getCollection()->transform(function ($booking) {
                $person   = $booking->serviceEvent?->customer?->person;
                $subtotal = $this->calculateSubtotalFromBooking($booking);
                $invoice  = $booking->invoice;

                return [
                    'booking_id'       => $booking->booking_id,
                    'booking_no'       => $booking->booking_no,
                    'has_invoice'      => (bool) $invoice,
                    'invoice_id'       => $invoice?->invoice_id,
                    'invoice_number'   => $invoice?->invoice_number,
                    'customer_name'    => $person?->full_name ?? 'Unknown',
                    'customer_email'   => $person?->email ?? 'N/A',
                    'customer_phone'   => $person?->phone ?? 'N/A',
                    'customer_address' => $person?->address_line_1,
                    'event_type'       => $booking->serviceEvent?->eventType?->name,
                    'event_date'       => $booking->serviceEvent?->event_date?->toDateString(),
                    'event_time'       => $booking->serviceEvent?->event_time,
                    'venue'            => $booking->serviceEvent?->venue,
                    'guests_count'     => $booking->serviceEvent?->guests_count ?? 0,
                    'subtotal'         => $subtotal,
                    'total_amount'     => $booking->quotation?->total_amount ?? $subtotal,
                    'required_deposit' => $booking->required_deposit ?? 0,
                    'status'           => $booking->booking_status,
                    'items'            => $booking->items->map(function ($item) {
                        return [
                            'description' => $item->custom_item_name ?? $item->menuItem?->name ?? 'Menu Item',
                            'quantity'    => (int) $item->quantity,
                            'unit_price'  => (float) $item->unit_price,
                        ];
                    }),
                ];
            });

            return $this->ok($bookings)
                ->header('Cache-Control', 'private, max-age=60');
        } catch (\Exception $e) {
            Log::error('Get confirmed bookings error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->fail('Failed to load confirmed bookings: ' . $e->getMessage(), 500);
        }
    }

    public function debts(Request $request)
    {
        try {
            $query = Invoice::with([
                'booking.serviceEvent.customer.person',
                'booking.serviceEvent.eventType',
                'booking.payments',
            ])
                ->whereRaw('paid_amount < total_amount')
                ->where('status', '!=', 'cancelled')
                ->whereHas('booking', fn($q) => $q->where('booking_no', 'not like', 'HIST-%'));

            if ($request->boolean('overdue_only')) {
                $query->whereDate('due_date', '<', today());
            }

            if ($request->filled('customer_id')) {
                $query->whereHas('booking.serviceEvent', function ($q) use ($request) {
                    $q->where('customer_id', $request->input('customer_id'));
                });
            }

            $rows = $query->latest('due_date')->paginate(
                min(500, max(1, $request->integer('per_page', 20)))
            );

            $rows->getCollection()->transform(function ($invoice) {
                $balance     = $invoice->total_amount - $invoice->paid_amount;
                $dueDate     = $invoice->due_date;
                $daysOverdue = $dueDate && $dueDate->isPast() ? $dueDate->diffInDays(now()) : 0;

                $paymentHistory = ($invoice->booking?->payments ?? collect())
                    ->where('status', 'completed')
                    ->sortByDesc('payment_date')
                    ->values()
                    ->map(function ($p) {
                        return [
                            'amount'        => $p->amount,
                            'signed_amount' => $p->payment_type === 'refund' ? -(float) $p->amount : (float) $p->amount,
                            'date'          => $p->payment_date?->toDateString(),
                            'method'        => $p->payment_method,
                            'type'          => $p->payment_type,
                            'status'        => $p->status,
                            'reference'     => $p->reference_number,
                        ];
                    });

                return [
                    'id'                 => $invoice->invoice_id,
                    'invoice_id'         => $invoice->invoice_id,
                    'invoice_number'     => $invoice->invoice_number ?? 'N/A',
                    'booking_id'         => $invoice->booking_id,
                    'booking_no'         => $invoice->booking?->booking_no ?? 'N/A',
                    'customer_name'      => $invoice->booking?->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                    'customer_email'     => $invoice->booking?->serviceEvent?->customer?->person?->email ?? 'N/A',
                    'customer_phone'     => $invoice->booking?->serviceEvent?->customer?->person?->phone ?? 'N/A',
                    'event_type'         => $invoice->booking?->serviceEvent?->eventType?->name,
                    'event_date'         => $invoice->booking?->serviceEvent?->event_date?->toDateString(),
                    'total_debt'         => (float) $invoice->total_amount,
                    'paid_debt'          => (float) $invoice->paid_amount,
                    'remaining_debt'     => (float) $balance,
                    'remaining_balance'  => (float) $balance,
                    'total_paid'         => (float) $invoice->paid_amount,
                    'payment_progress'   => (float) $invoice->total_amount > 0
                        ? round(min(100, ((float) $invoice->paid_amount / (float) $invoice->total_amount) * 100), 2)
                        : 0,
                    'next_payment'       => $balance > 0 ? $dueDate?->toDateString() : null,
                    'subtotal'           => (float) $invoice->subtotal,
                    'discount'           => (float) $invoice->discount,
                    'additional_charges' => (float) $invoice->additional_charges,
                    'due_date'           => $dueDate?->toDateString(),
                    'days_overdue'       => $daysOverdue,
                    'status'             => $invoice->status,
                    'payment_history'    => $paymentHistory,
                    'deposit_paid'       => $this->calculateDepositPaid($invoice),
                    'is_deposit_paid'    => $this->isDepositPaid($invoice),
                    'created_at'         => $invoice->created_at?->toDateTimeString(),
                ];
            });

            $base = Invoice::whereRaw('paid_amount < total_amount')
                ->where('status', '!=', 'cancelled')
                ->whereHas('booking', fn($q) => $q->where('booking_no', 'not like', 'HIST-%'));

            $summary = [
                'total_debt'              => (float) (clone $base)->sum(DB::raw('total_amount - paid_amount')),
                'overdue_debt'            => (float) (clone $base)->whereDate('due_date', '<', today())->sum(DB::raw('total_amount - paid_amount')),
                'overdue_count'           => (clone $base)->whereDate('due_date', '<', today())->count(),
                'total_invoices'          => (clone $base)->count(),
                'collection_rate'         => $this->calculateCollectionRate(),
                'deposit_collection_rate' => $this->calculateDepositCollectionRate(),
            ];

            return $this->ok(['data' => $rows, 'summary' => $summary]);
        } catch (\Exception $e) {
            Log::error('Debts error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->fail('Failed to load debts: ' . $e->getMessage(), 500);
        }
    }

    public function payments(Invoice $invoice)
    {
        try {
            $booking  = $invoice->booking;
            $payments = $booking
                ? $booking->payments()->latest('payment_id')->get()
                : collect();

            return $this->ok($payments->map(function ($payment) {
                return [
                    'id'             => $payment->payment_id,
                    'payment_number' => $payment->payment_number,
                    'amount'         => (float) $payment->amount,
                    'method'         => $payment->payment_method,
                    'type'           => $payment->payment_type,
                    'status'         => $payment->status,
                    'date'           => $payment->payment_date?->toDateString(),
                    'reference'      => $payment->reference_number,
                    'notes'          => $payment->notes,
                ];
            })->values());
        } catch (\Exception $e) {
            Log::error('Invoice payments error: ' . $e->getMessage(), [
                'invoice_id' => $invoice->invoice_id ?? null,
                'trace'      => $e->getTraceAsString(),
            ]);
            return $this->fail('Failed to load payments: ' . $e->getMessage(), 500);
        }
    }

    public function sendReminder(Request $request, Invoice $invoice)
    {
        try {
            $data = $request->validate([
                'subject' => ['required', 'string', 'max:200'],
                'message' => ['required', 'string'],
            ]);

            $invoice->loadMissing(['booking.serviceEvent.customer.person']);
            $customer = $invoice->booking?->serviceEvent?->customer;
            $person   = $customer?->person;
            $email    = $person?->email;

            $gmailStatus     = $email ? 'sent' : 'no_email';
            $messengerStatus = 'unavailable';

            if ($email) {
                try {
                    Mail::raw($data['message'], function ($mail) use ($email, $data, $invoice) {
                        $mail->to($email)->subject($data['subject'] ?: "Payment Reminder {$invoice->invoice_number}");
                    });
                } catch (\Throwable $e) {
                    $gmailStatus = 'failed';
                    Log::warning('Invoice Gmail reminder failed: ' . $e->getMessage(), ['invoice_id' => $invoice->invoice_id]);
                }
            }

            if ($customer?->customer_id) {
                try {
                    $thread = ChatThread::query()->firstOrCreate(
                        ['customer_id' => $customer->customer_id, 'status' => 'open'],
                        ['assigned_user_id' => null]
                    );

                    ChatMessage::query()->create([
                        'thread_id'      => $thread->thread_id,
                        'sender_user_id' => null,
                        'message'        => $data['message'],
                    ]);

                    $messengerStatus = 'sent';
                } catch (\Throwable $e) {
                    $messengerStatus = 'failed';
                    Log::warning('Invoice Messenger reminder failed: ' . $e->getMessage(), ['invoice_id' => $invoice->invoice_id]);
                }
            }

            if (!$customer?->user_id && !$email && $messengerStatus !== 'sent') {
                return $this->fail('Customer contact channel not found.', 422);
            }

            $deliveryStatus = ($gmailStatus === 'sent' || $messengerStatus === 'sent') ? 'Sent' : 'Failed';

            if ($customer?->user_id) {
                Notification::create([
                    'user_id'  => $customer->user_id,
                    'type'     => 'payment_reminder',
                    'priority' => Notification::PRIORITY_HIGH,
                    'title'    => $data['subject'],
                    'message'  => $data['message'],
                    'data'     => [
                        'invoice_id'                => $invoice->invoice_id,
                        'invoice_number'            => $invoice->invoice_number,
                        'gmail_delivery_status'     => $gmailStatus,
                        'messenger_delivery_status' => $messengerStatus,
                        'delivery_status'           => $deliveryStatus,
                    ],
                    'is_read'  => false,
                    'is_sent'  => true,
                    'sent_at'  => now(),
                ]);
            }

            return $this->ok([
                'delivery_status'           => $deliveryStatus,
                'gmail_delivery_status'     => $gmailStatus,
                'messenger_delivery_status' => $messengerStatus,
            ], 'Reminder sent successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('Send reminder error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->fail('Failed to send reminder: ' . $e->getMessage(), 500);
        }
    }

    public function download(Invoice $invoice)
    {
        try {
            $invoice->load(['booking.serviceEvent.customer.person', 'booking.items.menuItem']);
            return $this->ok($this->formatInvoice($invoice), 'Invoice data ready for download.');
        } catch (\Exception $e) {
            Log::error('Invoice download error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->fail('Failed to download invoice: ' . $e->getMessage(), 500);
        }
    }

    // ==================== PRIVATE HELPERS ====================

    private function formatInvoice($invoice): array
    {
        $invoice->loadMissing([
            'booking.serviceEvent.customer.person',
            'booking.serviceEvent.eventType',
            'booking.items.menuItem',
            'booking.charges',
            'booking.payments',
        ]);
        $booking = $invoice->booking;
        $event   = $booking?->serviceEvent;
        $person  = $event?->customer?->person;

        if (! $booking) {
            return [
                'id'               => $invoice->invoice_id,
                'invoice_id'       => $invoice->invoice_id,
                'invoice_number'   => $invoice->invoice_number ?? 'N/A',
                'booking_id'       => null,
                'booking_no'       => 'N/A',
                'customer_name'    => 'Unknown',
                'customer_email'   => 'N/A',
                'customer_phone'   => 'N/A',
                'event_type'       => 'General',
                'event_date'       => null,
                'subtotal'         => (float) $invoice->subtotal,
                'discount'         => (float) $invoice->discount,
                'discount_type'    => $invoice->discount_type ?? 'fixed',
                'additional_charges' => (float) $invoice->additional_charges,
                'total_amount'     => (float) $invoice->total_amount,
                'paid_amount'      => (float) $invoice->paid_amount,
                'balance'          => (float) ($invoice->total_amount - $invoice->paid_amount),
                'required_deposit' => round((float) $invoice->total_amount * 0.30, 2),
                'deposit_paid'     => 0.0,
                'is_deposit_paid'  => false,
                'status'           => $invoice->status,
                'issue_date'       => $invoice->created_at?->toDateString(),
                'due_date'         => $invoice->due_date?->toDateString(),
                'notes'            => $invoice->notes,
                'items'            => [],
                'payments'         => [],
            ];
        }

        return [
            'id'                 => $invoice->invoice_id,
            'invoice_id'         => $invoice->invoice_id,
            'invoice_number'     => $invoice->invoice_number ?? 'N/A',
            'booking_id'         => $booking->booking_id,
            'booking_no'         => $booking->booking_no ?? 'N/A',
            'customer_name'      => $person?->full_name ?? 'Unknown',
            'customer_email'     => $person?->email ?? 'N/A',
            'customer_phone'     => $person?->phone ?? 'N/A',
            'customer_address'   => $person?->address_line_1,
            'event_type'         => $event?->eventType?->name ?? 'General',
            'event_date'         => $event?->event_date?->toDateString(),
            'subtotal'           => (float) $invoice->subtotal,
            'discount'           => (float) $invoice->discount,
            'discount_type'      => $invoice->discount_type ?? 'fixed',
            'additional_charges' => (float) $invoice->additional_charges,
            'total_amount'       => (float) $invoice->total_amount,
            'paid_amount'        => (float) $invoice->paid_amount,
            'balance'            => (float) ($invoice->total_amount - $invoice->paid_amount),
            'required_deposit'   => round((float) $invoice->total_amount * 0.30, 2),
            'deposit_paid'       => $this->calculateDepositPaid($invoice),
            'is_deposit_paid'    => $this->isDepositPaid($invoice),
            'status'             => $invoice->status,
            'issue_date'         => $invoice->created_at?->toDateString(),
            'due_date'           => $invoice->due_date?->toDateString(),
            'notes'              => $invoice->notes,
            'items'              => $booking->items->map(function ($item) {
                return [
                    'description' => $item->custom_item_name ?? $item->menuItem?->name ?? 'Menu Item',
                    'quantity'    => (int) $item->quantity,
                    'unit_price'  => (float) $item->unit_price,
                    'total'       => (float) $item->unit_price * (int) $item->quantity,
                ];
            })->values(),
            // ⭐ Charge + discount rows so the Billing UI can render the
            //    breakdown beneath the invoice total.
            //    Guarded against a missing booking_charges table.
            'charges'            => Schema::hasTable('booking_charges')
                ? ($booking->relationLoaded('charges')
                    ? $booking->charges
                    : ($booking->charges()->get() ?? collect()))
                ->map(function ($charge) {
                    return [
                        'id'          => $charge->booking_charge_id,
                        'kind'        => $charge->charge_kind,
                        'type'        => $charge->charge_type,
                        'description' => $charge->description,
                        'amount'      => (float) $charge->amount,
                    ];
                })->values()
                : collect(),
            'payments' => ($booking->relationLoaded('payments')
                ? $booking->payments
                : ($booking->payments()->get() ?? collect()))
                ->where('status', 'completed')
                ->values()
                ->map(function ($p) {
                    return [
                        'id'        => $p->payment_id,
                        'amount'    => $p->amount,
                        'method'    => $p->payment_method,
                        'type'      => $p->payment_type,
                        'reference' => $p->reference_number,
                        'date'      => $p->payment_date?->toDateString(),
                    ];
                }),
        ];
    }

    private function calculateSubtotalFromBooking($booking): float
    {
        if (! $booking) {
            return 0.0;
        }

        if (! $booking->relationLoaded('items')) {
            $booking->loadMissing('items');
        }

        $total = 0;
        foreach (($booking->items ?? collect()) as $item) {
            $total += ((float) ($item->unit_price ?? 0)) * ((int) ($item->quantity ?? 1));
        }
        return (float) $total;
    }

    private function calculateTotalAmount(float $subtotal, float $discount, string $discountType, float $additionalCharges): float
    {
        $discountAmount = $discountType === 'percentage'
            ? $subtotal * ($discount / 100)
            : $discount;

        return max(0, $subtotal - $discountAmount + $additionalCharges);
    }

    private function calculateDepositPaid($invoice): float
    {
        $booking = $invoice->booking;

        if (! $booking) {
            return 0.0;
        }

        return (float) $booking->payments()
            ->where('payment_type', 'deposit')
            ->where('status', 'completed')
            ->sum('amount');
    }

    private function isDepositPaid($invoice): bool
    {
        $requiredDeposit = (float) ($invoice->booking?->required_deposit ?? 0);

        if ($requiredDeposit <= 0) {
            $requiredDeposit = (float) $invoice->total_amount * 0.30;
        }

        return $this->calculateDepositPaid($invoice) >= $requiredDeposit;
    }

    private function calculateCollectionRate(): float
    {
        $query = Invoice::where('status', '!=', 'cancelled')
            ->whereHas('booking', fn($q) => $q->where('booking_no', 'not like', 'HIST-%'));

        $total = (float) (clone $query)->sum('total_amount');
        $paid  = (float) (clone $query)->sum('paid_amount');

        return $total <= 0 ? 100 : round(($paid / $total) * 100, 2);
    }

    private function calculateDepositCollectionRate(): float
    {
        $bookings = Booking::whereIn('booking_status', ['confirmed', 'completed'])
            ->where('booking_no', 'not like', 'HIST-%')
            ->with(['quotation', 'payments'])
            ->get();

        $totalDeposits = 0;
        $paidDeposits  = 0;

        foreach ($bookings as $booking) {
            $required = (float) ($booking->required_deposit ?? ($booking->quotation?->total_amount * 0.3 ?? 0));
            $paid = (float) $booking->payments
                ->where('payment_type', 'deposit')
                ->where('status', 'completed')
                ->sum('amount');

            $totalDeposits += $required;
            $paidDeposits  += min($paid, $required);
        }

        return $totalDeposits <= 0 ? 100 : round(($paidDeposits / $totalDeposits) * 100, 2);
    }

    /**
     * ⭐ Delegate to the model helper so the invoice-number format lives
     * in exactly one place. See Invoice::nextInvoiceNumber().
     */
    private function generateInvoiceNumber(): string
    {
        return Invoice::nextInvoiceNumber();
    }
}
