<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\BookingRequest;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\EventTracking;
use App\Models\InventoryStock;
use App\Models\Setting;
use App\Models\PurchaseRequest;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\EventDay;
use App\Models\MealService;
use App\Services\BookingService;
use App\Services\InventoryService;
use App\Services\NotificationService;
use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use App\Mail\BookingConfirmationMail;
use Illuminate\Support\Facades\Mail;

class BookingController extends Controller
{
    use Auditable;

    private const SETTINGS_GROUP_DEPOSIT_POLICY  = 'booking_deposit_policy';
    private const SETTINGS_GROUP_REFUND_REQUESTS = 'booking_refund_requests';

    // ============================================================
    // PERIOD HELPERS — used by statistics() for Weekly/Monthly/Yearly
    // ============================================================

    /**
     * Resolve a period string + anchor date into [start, end, label].
     *
     * Filtering is done on service_events.event_date (the real event
     * date stored in the database), NOT bookings.created_at.
     */
    private function resolvePeriod(?string $period, ?string $anchor): array
    {
        $period     = strtolower((string) ($period ?: 'monthly'));
        $anchorDate = $anchor ? Carbon::parse($anchor) : now();

        switch ($period) {
            case 'weekly':
                $start = $anchorDate->copy()->startOfWeek(Carbon::MONDAY);
                $end   = $anchorDate->copy()->endOfWeek(Carbon::SUNDAY);
                $label = $start->format('M d') . ' – ' . $end->format('M d, Y');
                break;

            case 'yearly':
                $start = $anchorDate->copy()->startOfYear();
                $end   = $anchorDate->copy()->endOfYear();
                $label = $start->format('Y');
                break;

            case 'all':
                return [null, null, 'All time'];

            case 'monthly':
            default:
                $start = $anchorDate->copy()->startOfMonth();
                $end   = $anchorDate->copy()->endOfMonth();
                $label = $start->format('F Y');
                break;
        }

        return [$start, $end, $label];
    }

    /**
     * Apply an event-date range filter to a Booking query.
     * Returns the query unchanged when $start or $end is null.
     */
    private function applyPeriodFilter($query, ?Carbon $start, ?Carbon $end)
    {
        if (! $start || ! $end) {
            return $query;
        }

        return $query->whereHas('serviceEvent', function ($eventQuery) use ($start, $end) {
            $eventQuery->whereDate('event_date', '>=', $start->toDateString())
                ->whereDate('event_date', '<=', $end->toDateString());
        });
    }

    // ============================================================
    // RELATION HELPERS
    // ============================================================

    private function query()
    {
        return Booking::query()->with($this->bookingRelations());
    }

    private function bookingRelations(): array
    {
        $relations = [
            'serviceEvent.customer.person',
            'serviceEvent.eventType',
            'serviceEvent.package',
            'quotation',
            'items.menuItem.recipeIngredients.ingredient',
            'payments',
            'order',
            'invoice',
            'equipment.equipment',
            'tracking',
        ];

        if (Schema::hasTable('event_days')) {
            $relations[] = 'eventDays';
        }

        if (Schema::hasTable('meal_services')) {
            $relations[] = 'mealServices.menuItem';
            $relations[] = 'mealServices.package';

            if (Schema::hasTable('meal_service_filters')) {
                $relations[] = 'mealServices.filters';
            }

            if (Schema::hasTable('meal_service_custom_items')) {
                $relations[] = 'mealServices.customItems.menuItem';
            }

            if (Schema::hasTable('booking_items') && Schema::hasColumn('booking_items', 'meal_service_id')) {
                $relations[] = 'items.mealService';
                if (Schema::hasTable('event_days')) {
                    $relations[] = 'items.mealService.eventDay';
                }
            }
        }

        if (Schema::hasTable('booking_charges')) {
            $relations[] = 'charges';
        }

        return $relations;
    }

    private function loadedRelationCollection(Booking $booking, string $relation): Collection
    {
        return $booking->relationLoaded($relation)
            ? $booking->getRelation($relation)
            : collect();
    }

    private function expireStaleRescheduleRequests(): void
    {
        try {
            $service = app(\App\Services\BookingService::class);
            $service->expireCustomerRescheduleResponses();
        } catch (\Throwable $e) {
            Log::warning('Failed to expire stale reschedule requests: ' . $e->getMessage());
        }
    }

    // ============================================================
    // INDEX / SHOW
    // ============================================================

    public function index(Request $request): JsonResponse
    {
        $this->expireStaleRescheduleRequests();

        $query = $this->query();

        if (! $request->boolean('include_history')) {
            // ⭐ Keep the standard exclusion of archived "HIST-" rows, but
            // always allow rejected bookings through so they appear in
            // the Booking History tab and the Rejected KPI modal.
            $query->where(function ($q) {
                $q->where('booking_no', 'not like', 'HIST-%')
                    ->orWhere('booking_status', 'rejected');
            });
        }

        if ($request->filled('status_in')) {
            $statuses = collect(explode(',', (string) $request->input('status_in')))
                ->map(fn($status) => trim($status))
                ->filter()
                ->values()
                ->all();
            if (! empty($statuses)) {
                $query->whereIn('booking_status', $statuses);
            }
        } elseif ($request->filled('status')) {
            $query->where('booking_status', $request->string('status')->toString());
        }

        if ($request->filled('status_not_in')) {
            $excludedStatuses = collect(explode(',', (string) $request->input('status_not_in')))
                ->map(fn($status) => trim($status))
                ->filter()
                ->values()
                ->all();
            if (! empty($excludedStatuses)) {
                $query->whereNotIn('booking_status', $excludedStatuses);
            }
        }

        if ($request->filled('event_type_id')) {
            $query->whereHas('serviceEvent', function ($q) use ($request) {
                $q->where('event_type_id', (int) $request->input('event_type_id'));
            });
        }

        if ($request->filled('event_date')) {
            $query->whereHas('serviceEvent', function ($q) use ($request) {
                $q->whereDate('event_date', $request->string('event_date')->toString());
            });
        }

        if ($request->filled('date_from')) {
            $query->whereHas('serviceEvent', function ($q) use ($request) {
                $q->whereDate('event_date', '>=', $request->string('date_from')->toString());
            });
        }

        if ($request->filled('date_to')) {
            $query->whereHas('serviceEvent', function ($q) use ($request) {
                $q->whereDate('event_date', '<=', $request->string('date_to')->toString());
            });
        }

        if ($request->filled('booking_id')) {
            $bookingId = strtolower($request->string('booking_id')->toString());
            $query->where(function ($q) use ($bookingId) {
                $q->where('booking_no', 'like', "%{$bookingId}%")
                    ->orWhere('booking_id', $bookingId);
            });
        }

        if ($request->filled('customer_name')) {
            $customerName = strtolower($request->string('customer_name')->toString());
            $query->whereHas('serviceEvent.customer.person', function ($q) use ($customerName) {
                $q->whereRaw('LOWER(CONCAT(first_name, " ", last_name)) LIKE ?', ["%{$customerName}%"]);
            });
        }

        if ($request->filled('booking_scope')) {
            $scope = $request->string('booking_scope')->toString();
            if ($scope === 'multi_day') {
                $query->whereHas('serviceEvent', function ($q) {
                    $q->where('booking_scope', 'multi_day');
                });
            } else {
                $query->whereHas('serviceEvent', function ($q) {
                    $q->where('booking_scope', 'regular')->orWhereNull('booking_scope');
                });
            }
        }

        if ($request->filled('search')) {
            $search = strtolower($request->string('search')->toString());
            $query->where(function ($q) use ($search) {
                $q->where('booking_no', 'like', "%{$search}%")
                    ->orWhereHas('serviceEvent.customer.person', function ($person) use ($search) {
                        $person->whereRaw('LOWER(CONCAT(first_name, " ", last_name)) LIKE ?', ["%{$search}%"])
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('serviceEvent', function ($event) use ($search) {
                        $event->where('venue', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = $request->integer('per_page', 6);
        $perPage = max(1, min(100, $perPage));

        // ⭐ REQUEST #9: Approved bookings always float to the top of the table.
        // Applied BEFORE the schedule sort so it becomes the primary sort key.
        // Within the approved group, the existing date/time sorting is preserved.
        $query->orderByRaw(
            "CASE
                WHEN bookings.booking_status IN ('confirmed', 'approved') THEN 0
                ELSE 1
            END"
        );

        if ($request->string('sort')->toString() === 'event_schedule') {
            $query->leftJoin('service_events as schedule_events', 'bookings.service_event_id', '=', 'schedule_events.service_event_id')
                ->select('bookings.*')
                ->orderByRaw('CASE WHEN schedule_events.event_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('schedule_events.event_date');

            $driver = DB::connection()->getDriverName();
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $query->orderByRaw("COALESCE(STR_TO_DATE(schedule_events.event_time, '%h:%i %p'), STR_TO_DATE(schedule_events.event_time, '%H:%i'), '23:59:59')");
            } else {
                $query->orderBy('schedule_events.event_time');
            }

            $query->orderBy('bookings.booking_id');
        } else {
            $query->latest('booking_id');
        }

        $bookings = $query->paginate($perPage);

        $bookings->getCollection()->transform(function ($booking) {
            return $this->formatBooking($booking);
        });

        return $this->ok($bookings);
    }

    public function show(Booking $booking): JsonResponse
    {
        $booking = $this->query()->findOrFail($booking->booking_id);
        return $this->ok($this->formatBooking($booking));
    }

    // ============================================================
    // CONFIRMATION NOTIFICATIONS
    // ============================================================

    private function sendBookingConfirmation(Booking $booking): void
    {
        $customer     = $booking->serviceEvent?->customer;
        $person       = $customer?->person;
        $email        = $person?->email;
        $customerName = $person?->full_name ?? 'Customer';
        $bookingNo    = $booking->booking_no;
        $eventDate    = $booking->serviceEvent?->event_date?->format('F d, Y') ?? 'TBD';
        $eventTime    = $booking->serviceEvent?->event_time ?? 'TBD';
        $venue        = $booking->serviceEvent?->venue ?? 'TBD';
        $totalAmount  = number_format($booking->quotation?->total_amount ?? 0, 2);

        if ($email) {
            try {
                Mail::to($email)->send(new BookingConfirmationMail($booking));
                Log::info('Booking confirmation email sent to: ' . $email, [
                    'booking_id' => $booking->booking_id,
                    'booking_no' => $bookingNo,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send booking confirmation email: ' . $e->getMessage(), [
                    'booking_id' => $booking->booking_id,
                    'email'      => $email,
                ]);
            }
        }

        if ($customer && $customer->user_id) {
            try {
                app(NotificationService::class)->notifyUser(
                    $customer->user_id,
                    'booking_confirmed',
                    '🎉 Booking Confirmed!',
                    "Great news! Your booking {$bookingNo} has been CONFIRMED!\n\n" .
                        "📅 Event Date: {$eventDate}\n" .
                        "⏰ Time: {$eventTime}\n" .
                        "📍 Venue: {$venue}\n\n" .
                        "We look forward to serving you!",
                    \App\Models\Notification::PRIORITY_HIGH,
                    [
                        'booking_id' => $booking->booking_id,
                        'booking_no' => $bookingNo,
                        'event_date' => $eventDate,
                        'venue'      => $venue,
                    ],
                    "/customer/bookings/{$booking->booking_id}"
                );
            } catch (\Exception $e) {
                Log::error('Failed to send booking in-app notification: ' . $e->getMessage());
            }
        }

        if ($customer && $customer->user_id) {
            try {
                $user = \App\Models\User::find($customer->user_id);
                if ($user && $user->fcm_token) {
                    $this->sendMobilePushNotification(
                        $user,
                        '🎉 Booking Confirmed!',
                        "Your booking {$bookingNo} has been confirmed for {$eventDate} at {$eventTime}.",
                        [
                            'booking_id' => (string) $booking->booking_id,
                            'booking_no' => $bookingNo,
                            'type'       => 'booking_confirmed',
                        ]
                    );
                }
            } catch (\Exception $e) {
                Log::error('Failed to send mobile push notification: ' . $e->getMessage());
            }
        }
    }

    private function sendMobilePushNotification($user, $title, $body, $data = []): void
    {
        try {
            $fcmToken = $user->fcm_token ?? null;
            if (!$fcmToken) return;

            $serverKey = config('services.fcm.server_key');
            if (!$serverKey) return;

            $payload = [
                'to'           => $fcmToken,
                'notification' => ['title' => $title, 'body' => $body, 'sound' => 'default'],
                'data'         => array_merge($data, ['click_action' => 'FLUTTER_NOTIFICATION_CLICK']),
                'priority'     => 'high',
            ];

            $ch = curl_init('https://fcm.googleapis.com/fcm/send');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: key=' . $serverKey,
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_exec($ch);
            curl_close($ch);
        } catch (\Exception $e) {
            Log::error('Failed to send mobile push notification: ' . $e->getMessage());
        }
    }

    // ============================================================
    // STORE / APPROVE / UPDATE / DESTROY
    // ============================================================

    public function store(BookingRequest $request, BookingService $service): JsonResponse
    {
        try {
            $customer = null;
            if ($request->has('customer_id')) {
                $customer = \App\Models\Customer::find($request->customer_id);
            }

            $booking = $service->requestBooking($customer, $request->validated());

            $payload = [
                'booking_id'     => $booking->booking_id,
                'booking_no'     => $booking->booking_no,
                'booking_status' => $booking->booking_status,
                'event_date'     => optional($booking->serviceEvent?->event_date)->format('Y-m-d'),
                'customer_name'  => $booking->serviceEvent?->customer?->person?->full_name,
                'total_amount'   => $booking->quotation?->total_amount,
            ];

            $bookingId = $booking->booking_id;

            app()->terminating(function () use ($bookingId) {
                try {
                    $booking = Booking::with(['serviceEvent.customer.person', 'quotation'])->find($bookingId);
                    if (!$booking) return;

                    $this->logCustom(
                        'store',
                        'bookings',
                        $booking->booking_id,
                        "Booking {$booking->booking_no} created",
                        [
                            'booking_no'   => $booking->booking_no,
                            'customer'     => $booking->serviceEvent?->customer?->person?->full_name,
                            'event_date'   => $booking->serviceEvent?->event_date?->format('Y-m-d'),
                            'guests_count' => $booking->serviceEvent?->guests_count,
                            'total_amount' => $booking->quotation?->total_amount,
                            'created_at'   => now()->toDateTimeString(),
                        ]
                    );

                    app(NotificationService::class)->bookingRequestReceived($booking);
                } catch (\Throwable $e) {
                    Log::warning('Booking post-create side effects failed: ' . $e->getMessage());
                }
            });

            return $this->ok($payload, 'Booking created successfully.');
        } catch (ValidationException $e) {
            return $this->fail(
                $e->validator->errors()->first('booking')
                    ?: $e->validator->errors()->first()
                    ?: 'Booking does not satisfy the current booking policy.',
                422,
                $e->errors()
            );
        } catch (\Throwable $e) {
            Log::error('Booking store error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to create booking: ' . $e->getMessage(), 500);
        }
    }

    public function approve(Booking $booking, BookingService $service): JsonResponse
    {
        try {
            $booking->load('serviceEvent');
            $oldData = $booking->toArray();

            if (!$booking->serviceEvent) {
                return $this->fail('Cannot approve booking: No service event associated with this booking.', 422);
            }

            try {
                $booking = $service->approve($booking);
            } catch (\Exception $e) {
                return $this->fail('Failed to approve booking: ' . $e->getMessage(), 500);
            }

            $booking = Booking::with([
                'serviceEvent.customer.person',
                'serviceEvent.eventType',
                'quotation',
                'invoice',
                'order',
                'payments',
            ])->findOrFail($booking->booking_id);

            try {
                app(NotificationService::class)->bookingApproved($booking);
                app(NotificationService::class)->depositRequired($booking);
            } catch (\Throwable $e) {
                Log::warning('Approval notification failed: ' . $e->getMessage());
            }

            $approvedBookingId = $booking->booking_id;
            $approvedBookingNo = $booking->booking_no;
            $orderNumber       = $booking->order?->order_number;

            app()->terminating(function () use ($approvedBookingId, $oldData, $approvedBookingNo) {
                try {
                    $booking = Booking::with([
                        'serviceEvent.customer.person',
                        'serviceEvent.eventType',
                        'quotation',
                        'order',
                    ])->find($approvedBookingId);

                    if (!$booking) return;

                    try {
                        $this->sendBookingConfirmation($booking);
                    } catch (\Throwable $notificationError) {
                        Log::warning('Failed to send booking confirmation notifications: ' . $notificationError->getMessage());
                    }

                    $customerName = $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown';

                    $this->logCustom(
                        'approve',
                        'bookings',
                        $booking->booking_id,
                        "Booking {$booking->booking_no} APPROVED",
                        [
                            'booking_no'    => $booking->booking_no,
                            'customer'      => $customerName,
                            'event_date'    => $booking->serviceEvent?->event_date?->format('Y-m-d'),
                            'total_amount'  => $booking->quotation?->total_amount,
                            'old_status'    => $oldData['booking_status'] ?? 'pending',
                            'new_status'    => 'confirmed',
                            'order_created' => $booking->order?->order_number,
                            'approved_at'   => now()->toDateTimeString(),
                        ]
                    );

                    app(NotificationService::class)->notifyRole(
                        'admin',
                        'booking_approved',
                        '🎉 Booking Approved Successfully',
                        "Booking {$booking->booking_no} has been approved and confirmed.\n\n" .
                            "👤 Customer: " . ($booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown') . "\n" .
                            "📅 Event Date: " . ($booking->serviceEvent?->event_date?->format('Y-m-d') ?? 'TBD') . "\n" .
                            "💰 Amount: ₱" . number_format($booking->quotation?->total_amount ?? 0, 2),
                        \App\Models\Notification::PRIORITY_HIGH,
                        [
                            'booking_id'    => $booking->booking_id,
                            'booking_no'    => $booking->booking_no,
                            'customer_name' => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                        ],
                        "/admin/bookings/{$booking->booking_id}"
                    );
                } catch (\Throwable $sideEffectError) {
                    Log::warning('Booking approval side-effect failed after response: ' . $sideEffectError->getMessage());
                }
            });

            $booking->loadMissing([
                'serviceEvent.customer.person',
                'serviceEvent.eventType',
                'quotation',
                'invoice',
                'order',
                'payments',
            ]);

            $event       = $booking->serviceEvent;
            $person      = $event?->customer?->person;
            $totalAmount = (float) ($booking->invoice?->total_amount ?? $booking->quotation?->total_amount ?? 0);
            $paidAmount  = (float) $booking->payments->where('status', 'completed')->sum('amount');

            $payload = [
                'id'               => $booking->booking_id,
                'booking_id'       => $booking->booking_id,
                'booking_no'       => $approvedBookingNo,
                'booking_status'   => 'confirmed',
                'order_number'     => $orderNumber,
                'order'            => $booking->order,
                'invoice'          => $booking->invoice,
                'quotation'        => $booking->quotation,
                'customer_name'    => $person?->full_name ?? 'Unknown',
                'customer_email'   => $person?->email,
                'customer_phone'   => $person?->phone,
                'customer_address' => $person?->address_line_1,
                'event_type_id'    => $event?->event_type_id,
                'event_type_name'  => $event?->eventType?->name,
                'booking_scope'    => $event?->booking_scope ?? 'regular',
                'event_date'       => $event?->event_date?->toDateString(),
                'event_time'       => $event?->event_time,
                'venue'            => $event?->venue,
                'guests_count'     => (int) ($event?->guests_count ?? 0),
                'service_type'     => $event?->service_type,
                'delivery_method'  => $event?->delivery_method,
                'special_requests' => $event?->special_requests,
                'total_amount'     => $totalAmount,
                'paid_amount'      => $paidAmount,
                'balance'          => max(0, $totalAmount - $paidAmount),
                'payments'         => $booking->payments->values(),
                'created_at'       => $booking->created_at,
                'updated_at'       => $booking->updated_at,
            ];

            return $this->ok($payload, 'Booking ' . $approvedBookingNo . ' confirmed successfully!');
        } catch (\Exception $e) {
            Log::error('Booking approval failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to approve booking: ' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, Booking $booking, BookingService $service): JsonResponse
    {
        try {
            $user = $request->user();
            $isCashier = $user?->hasAnyRole(['cashier', 'finance', 'finance-staff', 'finance_staff']) ?? false;
            $isAdministrator = $user?->hasAnyRole(['admin', 'administrator', 'owner', 'super-admin', 'super_admin', 'superadmin']) ?? false;

            if ($isCashier && ! $isAdministrator) {
                if (! in_array($booking->booking_status, ['pending', 'draft'], true)) {
                    return $this->fail('Cashiers may only update pending booking requests.', 403);
                }
                if ($request->hasAny(['booking_status', 'cancellation_reason'])) {
                    return $this->fail('Booking approval, rejection, cancellation, and status changes require administrator approval.', 403);
                }
            }

            $oldData = $booking->toArray();

            if ($request->has('meal_services') || $request->has('charges') || $request->has('transportation_fee') || $request->has('event_date')) {
                $booking = $service->updateBookingFromAdmin($booking, $request->all());
            } else {
                $booking->update($request->only([
                    'booking_status',
                    'requested_date',
                    'requested_time',
                    'reschedule_reason',
                    'reschedule_proposed_by',
                    'reschedule_status',
                    'reschedule_source',
                    'reschedule_proposed_at',
                    'original_event_date',
                    'original_event_time',
                    'cancellation_reason',
                ]));

                $booking->serviceEvent?->update($request->only([
                    'event_date',
                    'event_end_date',
                    'event_time',
                    'venue',
                    'guests_count',
                    'service_type',
                    'delivery_method',
                    'special_requests',
                    'delivery_address',
                ]));
            }

            $this->logCustom(
                'update',
                'bookings',
                $booking->booking_id,
                "Booking {$booking->booking_no} updated",
                [
                    'booking_no' => $booking->booking_no,
                    'old_status' => $oldData['booking_status'] ?? 'pending',
                    'new_status' => $booking->booking_status,
                    'updated_at' => now()->toDateTimeString(),
                ]
            );

            $booking = $this->query()->findOrFail($booking->booking_id);
            return $this->ok($this->formatBooking($booking), 'Booking updated.');
        } catch (\Exception $e) {
            Log::error('Booking update error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to update booking: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(Booking $booking): JsonResponse
    {
        try {
            $oldData = $booking->toArray();

            $this->logCustom(
                'delete',
                'bookings',
                $booking->booking_id,
                "Booking {$booking->booking_no} archived",
                [
                    'booking_no'     => $booking->booking_no,
                    'booking_status' => $oldData['booking_status'] ?? 'unknown',
                    'deleted_at'     => now()->toDateTimeString(),
                ]
            );

            $booking->delete();
            return $this->ok(null, 'Booking archived.');
        } catch (\Exception $e) {
            Log::error('Booking delete error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to delete booking: ' . $e->getMessage(), 500);
        }
    }

    public function reject(Booking $booking): JsonResponse
    {
        try {
            $oldData = $booking->toArray();

            $booking->update(['booking_status' => 'rejected']);
            $booking->serviceEvent?->update(['status' => 'cancelled']);

            $this->logCustom(
                'reject',
                'bookings',
                $booking->booking_id,
                "Booking {$booking->booking_no} REJECTED",
                [
                    'booking_no' => $booking->booking_no,
                    'customer'   => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                    'old_status' => $oldData['booking_status'] ?? 'pending',
                    'new_status' => 'rejected',
                    'rejected_at' => now()->toDateTimeString(),
                ]
            );

            $customer = $booking->serviceEvent?->customer;
            if ($customer && $customer->user_id) {
                app(NotificationService::class)->notifyUser(
                    $customer->user_id,
                    'booking_rejected',
                    'Booking Update',
                    "We regret to inform you that your booking {$booking->booking_no} has been rejected. Please contact us for more information.",
                    \App\Models\Notification::PRIORITY_MEDIUM,
                    ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                    "/customer/bookings/{$booking->booking_id}"
                );
            }

            app(NotificationService::class)->notifyRole(
                'admin',
                'booking_rejected',
                '❌ Booking Rejected',
                "Booking {$booking->booking_no} has been rejected.",
                \App\Models\Notification::PRIORITY_MEDIUM,
                ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                "/admin/bookings/{$booking->booking_id}"
            );

            return $this->ok($this->formatBooking($booking->fresh()), 'Booking rejected.');
        } catch (\Exception $e) {
            Log::error('Booking reject error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to reject booking: ' . $e->getMessage(), 500);
        }
    }

    /**
     * ⭐ REQUEST #4, #5 — Restore a rejected booking back to pending approval.
     */
    public function unreject(Request $request, Booking $booking): JsonResponse
    {
        try {
            $oldData = $booking->toArray();

            if (strtolower((string) $booking->booking_status) !== 'rejected') {
                return $this->fail('Only rejected bookings can be un-rejected.', 422);
            }

            DB::transaction(function () use ($booking) {
                $booking->update([
                    'booking_status'  => 'pending_approval',
                    'cancellation_reason' => null,
                ]);
                $booking->serviceEvent?->update(['status' => 'pending']);
            });

            $this->logCustom(
                'unreject',
                'bookings',
                $booking->booking_id,
                "Booking {$booking->booking_no} UN-REJECTED (restored to pending)",
                [
                    'booking_no' => $booking->booking_no,
                    'customer'   => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                    'old_status' => $oldData['booking_status'] ?? 'rejected',
                    'new_status' => 'pending_approval',
                    'unrejected_at' => now()->toDateTimeString(),
                ]
            );

            try {
                app(NotificationService::class)->notifyRole(
                    'admin',
                    'booking_unrejected',
                    '↩️ Booking Restored',
                    "Booking {$booking->booking_no} has been restored to pending approval.",
                    \App\Models\Notification::PRIORITY_MEDIUM,
                    ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                    "/admin/bookings/{$booking->booking_id}"
                );
            } catch (\Throwable $e) {
                Log::warning('Un-reject notification failed: ' . $e->getMessage());
            }

            return $this->ok($this->formatBooking($booking->fresh()), 'Booking restored to pending approval.');
        } catch (\Throwable $e) {
            Log::error('Un-reject booking error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to restore booking: ' . $e->getMessage(), 500);
        }
    }

    public function cancel(Request $request, Booking $booking, BookingService $service): JsonResponse
    {
        try {
            $oldData = $booking->toArray();
            $reason  = $request->input('reason');

            $booking = $service->cancel($booking, $reason);

            $this->logCustom(
                'cancel',
                'bookings',
                $booking->booking_id,
                "Booking {$booking->booking_no} CANCELLED",
                [
                    'booking_no'    => $booking->booking_no,
                    'customer'      => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                    'reason'        => $reason,
                    'old_status'    => $oldData['booking_status'] ?? 'pending',
                    'new_status'    => 'cancelled',
                    'cancelled_at'  => now()->toDateTimeString(),
                ]
            );

            app(NotificationService::class)->bookingCancelled($booking, $reason);

            return $this->ok($this->formatBooking($booking->fresh()), 'Booking cancelled.');
        } catch (ValidationException $e) {
            return $this->fail(
                $e->validator->errors()->first('cancellation')
                    ?: $e->validator->errors()->first()
                    ?: 'Cancellation is not allowed at this time.',
                422,
                $e->errors()
            );
        } catch (\Exception $e) {
            Log::error('Booking cancel error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to cancel booking: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // LEGACY RESCHEDULE METHODS
    // ============================================================

    public function reschedule(Request $request, Booking $booking): JsonResponse
    {
        try {
            $oldData = $booking->toArray();

            $validated = $request->validate([
                'new_date'   => ['nullable', 'date'],
                'event_date' => ['nullable', 'date'],
                'new_time'   => ['nullable', 'string'],
                'event_time' => ['nullable', 'string'],
                'reason'     => ['nullable', 'string'],
            ]);

            $booking->serviceEvent?->update([
                'event_date' => $validated['new_date'] ?? $validated['event_date'] ?? $booking->serviceEvent->event_date,
                'event_time' => $validated['new_time'] ?? $validated['event_time'] ?? $booking->serviceEvent->event_time,
            ]);

            $booking->update([
                'booking_status'    => 'confirmed',
                'reschedule_reason' => $validated['reason'] ?? null,
            ]);

            $this->logCustom(
                'reschedule',
                'bookings',
                $booking->booking_id,
                "Booking {$booking->booking_no} RESCHEDULED",
                [
                    'booking_no'     => $booking->booking_no,
                    'customer'       => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                    'old_date'       => $oldData['event_date'] ?? null,
                    'new_date'       => $booking->serviceEvent?->event_date?->toDateString(),
                    'old_time'       => $oldData['event_time'] ?? null,
                    'new_time'       => $booking->serviceEvent?->event_time,
                    'reason'         => $validated['reason'] ?? null,
                    'rescheduled_at' => now()->toDateTimeString(),
                ]
            );

            $customer = $booking->serviceEvent?->customer;
            if ($customer && $customer->user_id) {
                app(NotificationService::class)->notifyUser(
                    $customer->user_id,
                    'booking_rescheduled',
                    'Booking Rescheduled',
                    "Your booking {$booking->booking_no} has been rescheduled to {$booking->serviceEvent->event_date} at {$booking->serviceEvent->event_time}.",
                    \App\Models\Notification::PRIORITY_MEDIUM,
                    ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                    "/customer/bookings/{$booking->booking_id}"
                );
            }

            app(NotificationService::class)->notifyRole(
                'admin',
                'booking_rescheduled',
                '🔄 Booking Rescheduled',
                "Booking {$booking->booking_no} has been rescheduled to {$booking->serviceEvent->event_date} at {$booking->serviceEvent->event_time}.",
                \App\Models\Notification::PRIORITY_MEDIUM,
                ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                "/admin/bookings/{$booking->booking_id}"
            );

            return $this->ok($this->formatBooking($booking->fresh()), 'Booking rescheduled.');
        } catch (\Exception $e) {
            Log::error('Booking reschedule error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to reschedule booking: ' . $e->getMessage(), 500);
        }
    }

    public function requestReschedule(Request $request, Booking $booking): JsonResponse
    {
        try {
            $validated = $request->validate([
                'requested_date' => ['required', 'date'],
                'requested_time' => ['required', 'string', 'max:50'],
                'reason'         => ['required', 'string', 'max:500'],
            ]);

            $oldData = $booking->toArray();
            $newDate = $validated['requested_date'];
            $newTime = $validated['requested_time'];
            $reason  = $validated['reason'];

            $booking->update([
                'booking_status'         => 'reschedule_requested',
                'requested_date'         => $newDate,
                'requested_time'         => $newTime,
                'reschedule_reason'      => $reason,
                'reschedule_proposed_by' => 'customer',
                'reschedule_status'      => 'pending',
                'reschedule_source'      => 'customer_initial',
                'reschedule_proposed_at' => now(),
            ]);

            $this->logCustom(
                'reschedule_requested',
                'bookings',
                $booking->booking_id,
                "Reschedule REQUESTED for booking {$booking->booking_no}",
                [
                    'booking_no'     => $booking->booking_no,
                    'customer'       => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                    'requested_date' => $newDate,
                    'requested_time' => $newTime,
                    'reason'         => $reason,
                    'old_status'     => $oldData['booking_status'] ?? 'pending',
                    'new_status'     => 'reschedule_requested',
                    'requested_at'   => now()->toDateTimeString(),
                ]
            );

            app(NotificationService::class)->bookingRescheduleRequested($booking, $newDate, $newTime, $reason);

            return $this->ok($this->formatBooking($booking->fresh()), 'Reschedule requested.');
        } catch (ValidationException $e) {
            return $this->fail($e->validator->errors()->first(), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('Request reschedule error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to request reschedule: ' . $e->getMessage(), 500);
        }
    }

    public function approveReschedule(Booking $booking): JsonResponse
    {
        try {
            $oldData = $booking->toArray();

            $booking->serviceEvent?->update([
                'event_date' => $booking->requested_date ?? $booking->serviceEvent->event_date,
                'event_time' => $booking->requested_time ?? $booking->serviceEvent->event_time,
            ]);
            $booking->update([
                'booking_status' => 'confirmed',
                'reschedule_status' => 'accepted',
                'requested_date' => null,
                'requested_time' => null,
                'reschedule_proposed_by' => null,
                'reschedule_proposed_at' => null,
                'original_event_date' => null,
                'original_event_time' => null,
            ]);
            $this->handleConfirmedBooking($booking);

            $this->logCustom(
                'reschedule_approved',
                'bookings',
                $booking->booking_id,
                "Reschedule request APPROVED for booking {$booking->booking_no}",
                [
                    'booking_no'    => $booking->booking_no,
                    'customer'      => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                    'approved_date' => $booking->serviceEvent?->event_date?->toDateString(),
                    'approved_time' => $booking->serviceEvent?->event_time,
                    'old_status'    => $oldData['booking_status'] ?? 'pending',
                    'new_status'    => 'confirmed',
                    'approved_at'   => now()->toDateTimeString(),
                ]
            );

            $customer = $booking->serviceEvent?->customer;
            if ($customer && $customer->user_id) {
                app(NotificationService::class)->notifyUser(
                    $customer->user_id,
                    'reschedule_approved',
                    'Reschedule Request Approved',
                    "Your reschedule request for booking {$booking->booking_no} has been approved. New date: {$booking->serviceEvent->event_date} at {$booking->serviceEvent->event_time}",
                    \App\Models\Notification::PRIORITY_HIGH,
                    ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                    "/customer/bookings/{$booking->booking_id}"
                );
            }

            app(NotificationService::class)->notifyRole(
                'admin',
                'reschedule_approved',
                '✅ Reschedule Request Approved',
                "The reschedule request for booking {$booking->booking_no} has been approved. New date: {$booking->serviceEvent->event_date} at {$booking->serviceEvent->event_time}",
                \App\Models\Notification::PRIORITY_MEDIUM,
                ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                "/admin/bookings/{$booking->booking_id}"
            );

            return $this->ok($this->formatBooking($booking->fresh()), 'Reschedule request approved.');
        } catch (\Exception $e) {
            Log::error('Approve reschedule error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to approve reschedule: ' . $e->getMessage(), 500);
        }
    }

    public function rejectReschedule(Booking $booking): JsonResponse
    {
        try {
            $oldData = $booking->toArray();
            $booking->update(['booking_status' => 'confirmed']);
            $this->handleConfirmedBooking($booking);

            $this->logCustom(
                'reschedule_rejected',
                'bookings',
                $booking->booking_id,
                "Reschedule request REJECTED for booking {$booking->booking_no}",
                [
                    'booking_no'  => $booking->booking_no,
                    'customer'    => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                    'old_status'  => $oldData['booking_status'] ?? 'pending',
                    'new_status'  => 'confirmed',
                    'rejected_at' => now()->toDateTimeString(),
                ]
            );

            $customer = $booking->serviceEvent?->customer;
            if ($customer && $customer->user_id) {
                app(NotificationService::class)->notifyUser(
                    $customer->user_id,
                    'reschedule_rejected',
                    'Reschedule Request Update',
                    "We regret to inform you that your reschedule request for booking {$booking->booking_no} has been rejected. Please contact us for alternative options.",
                    \App\Models\Notification::PRIORITY_MEDIUM,
                    ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                    "/customer/bookings/{$booking->booking_id}"
                );
            }

            app(NotificationService::class)->notifyRole(
                'admin',
                'reschedule_rejected',
                '❌ Reschedule Request Rejected',
                "The reschedule request for booking {$booking->booking_no} has been rejected.",
                \App\Models\Notification::PRIORITY_LOW,
                ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                "/admin/bookings/{$booking->booking_id}"
            );

            return $this->ok($this->formatBooking($booking->fresh()), 'Reschedule request rejected.');
        } catch (\Exception $e) {
            Log::error('Reject reschedule error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to reject reschedule: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // TWO-WAY RESCHEDULE WORKFLOW
    // ============================================================

    private function validateRescheduleDate(Carbon $newDate, ?Booking $booking = null, ?string $newTime = null): ?array
    {
        $today  = now()->startOfDay();
        $target = $newDate->copy()->startOfDay();

        if ($target->lt($today)) {
            return ['code' => 'past_date', 'message' => 'The proposed date is in the past.'];
        }

        $setting = Setting::where('group', 'booking_calendar')
            ->where('key', $target->toDateString())
            ->first();

        if ($setting) {
            $value       = json_decode($setting->value, true) ?: [];
            $status      = $value['status'] ?? 'available';
            $opMode      = $value['operation_mode'] ?? 'normal';
            $maxBookings = (int) ($value['max_bookings'] ?? 0);

            $bookingCount = Booking::whereIn('booking_status', [
                'confirmed',
                'pending_approval',
                'ongoing',
                'reschedule_proposed',
            ])
                ->when($booking, fn($q) => $q->where('booking_id', '!=', $booking->booking_id))
                ->whereHas(
                    'serviceEvent',
                    fn($q) =>
                    $q->whereDate('event_date', $target->toDateString())
                )
                ->count();

            if ($status !== 'available') {
                return [
                    'code'    => 'date_unavailable',
                    'message' => "The date {$target->toDateString()} is marked as {$status}.",
                ];
            }

            if ($opMode === 'limited_slot' && $maxBookings > 0 && $bookingCount >= $maxBookings) {
                return [
                    'code'    => 'slot_full',
                    'message' => "The date {$target->toDateString()} is fully booked ({$bookingCount}/{$maxBookings}).",
                ];
            }
        }

        return null;
    }

    public function rejectWithReschedule(Request $request, Booking $booking): JsonResponse
    {
        try {
            $validated = $request->validate([
                'new_date' => ['required', 'date'],
                'new_time' => ['nullable', 'string', 'max:50'],
                'reason'   => ['required', 'string', 'max:500'],
            ]);

            $booking->loadMissing('serviceEvent');
            if (!$booking->serviceEvent) {
                return $this->fail('Booking has no service event.', 422);
            }

            $newDate = Carbon::parse($validated['new_date']);
            $error   = $this->validateRescheduleDate($newDate, $booking);
            if ($error) {
                return $this->fail($error['message'], 422, ['code' => $error['code']]);
            }

            $oldStatus    = $booking->booking_status;
            $oldEventDate = $booking->serviceEvent->event_date?->toDateString();
            $oldEventTime = $booking->serviceEvent->event_time;

            DB::transaction(function () use ($booking, $validated, $newDate, $oldEventDate, $oldEventTime) {
                $booking->update([
                    'booking_status'         => $booking->booking_status ?: 'confirmed',
                    'requested_date'         => $newDate->toDateString(),
                    'requested_time'         => $validated['new_time'] ?? $booking->serviceEvent->event_time,
                    'reschedule_reason'      => $validated['reason'],
                    'reschedule_proposed_by' => 'admin',
                    'reschedule_status'      => 'pending',
                    'reschedule_source'      => 'admin_proposal',
                    'reschedule_proposed_at' => now(),
                    'original_event_date'    => $oldEventDate,
                    'original_event_time'    => $oldEventTime,
                ]);
            });

            $this->logCustom(
                'reject_with_reschedule',
                'bookings',
                $booking->booking_id,
                "Admin proposed reschedule for {$booking->booking_no}",
                [
                    'booking_no' => $booking->booking_no,
                    'old_status' => $oldStatus,
                    'new_date'   => $newDate->toDateString(),
                    'reason'     => $validated['reason'],
                ]
            );

            try {
                app(NotificationService::class)->rescheduleProposedByAdmin(
                    $booking->fresh(),
                    $newDate,
                    $validated['new_time'] ?? null,
                    $validated['reason']
                );
            } catch (\Throwable $e) {
                Log::warning('Reschedule proposal notification failed: ' . $e->getMessage());
            }

            return $this->ok($this->formatBooking($booking->fresh()), 'Reschedule proposal sent.');
        } catch (ValidationException $e) {
            return $this->fail($e->validator->errors()->first(), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('rejectWithReschedule error: ' . $e->getMessage());
            return $this->fail('Failed to propose reschedule: ' . $e->getMessage(), 500);
        }
    }

    public function acceptAdminReschedule(Booking $booking): JsonResponse
    {
        try {
            if ($booking->reschedule_status !== 'pending' || $booking->reschedule_proposed_by !== 'admin') {
                return $this->fail('No pending admin reschedule proposal for this booking.', 422);
            }

            $newDate = $booking->requested_date ? Carbon::parse($booking->requested_date) : null;
            if (!$newDate) {
                return $this->fail('Missing proposed date.', 422);
            }

            $booking->loadMissing('serviceEvent');

            DB::transaction(function () use ($booking, $newDate) {
                $booking->serviceEvent?->update([
                    'event_date' => $newDate->toDateString(),
                    'event_time' => $booking->requested_time ?? $booking->serviceEvent->event_time,
                ]);
                $booking->update([
                    'booking_status'         => 'confirmed',
                    'reschedule_status'      => 'accepted',
                    'reschedule_proposed_by' => 'admin',
                    'requested_date'         => null,
                    'requested_time'         => null,
                    'reschedule_reason'      => null,
                    'reschedule_proposed_at' => null,
                    'original_event_date'    => null,
                    'original_event_time'    => null,
                ]);
            });

            $this->logCustom(
                'reschedule_accepted_by_customer',
                'bookings',
                $booking->booking_id,
                "Customer accepted reschedule for {$booking->booking_no}",
                [
                    'booking_no' => $booking->booking_no,
                    'new_date'   => $newDate->toDateString(),
                ]
            );

            return $this->ok($this->formatBooking($booking->fresh()), 'Reschedule accepted.');
        } catch (\Throwable $e) {
            Log::error('acceptAdminReschedule error: ' . $e->getMessage());
            return $this->fail('Failed to accept reschedule: ' . $e->getMessage(), 500);
        }
    }

    public function counterReschedule(Request $request, Booking $booking): JsonResponse
    {
        try {
            if ($booking->reschedule_status !== 'pending' || $booking->reschedule_proposed_by !== 'admin') {
                return $this->fail('No pending admin reschedule proposal to counter.', 422);
            }

            $validated = $request->validate([
                'new_date' => ['required', 'date'],
                'new_time' => ['nullable', 'string', 'max:50'],
                'reason'   => ['nullable', 'string', 'max:500'],
            ]);

            $newDate = Carbon::parse($validated['new_date']);
            $error   = $this->validateRescheduleDate($newDate, $booking);
            if ($error) {
                return $this->fail($error['message'], 422, ['code' => $error['code']]);
            }

            // ⭐ Keep booking_status as confirmed — reschedule is a sub-state
            $restoreStatus = in_array($booking->booking_status, ['confirmed', 'ongoing'], true)
                ? $booking->booking_status
                : 'confirmed';

            $booking->update([
                'booking_status'         => $restoreStatus,
                'requested_date'         => $newDate->toDateString(),
                'requested_time'         => $validated['new_time'] ?? $booking->requested_time,
                'reschedule_reason'      => $validated['reason'] ?? $booking->reschedule_reason,
                'reschedule_proposed_by' => 'customer',
                'reschedule_status'      => 'pending',
                'reschedule_source'      => 'customer_counter',
                'reschedule_proposed_at' => now(),
            ]);

            $this->logCustom(
                'counter_reschedule',
                'bookings',
                $booking->booking_id,
                "Customer counter-proposed date for {$booking->booking_no}",
                [
                    'booking_no' => $booking->booking_no,
                    'new_date'   => $newDate->toDateString(),
                    'new_time'   => $validated['new_time'] ?? null,
                    'reason'     => $validated['reason'] ?? null,
                ]
            );

            try {
                app(NotificationService::class)->rescheduleCounterProposed(
                    $booking->fresh(),
                    $newDate,
                    $validated['reason'] ?? null
                );
            } catch (\Throwable $e) {
                Log::warning('Counter reschedule notification failed: ' . $e->getMessage());
            }

            try {
                app(NotificationService::class)->notifyRole(
                    'admin',
                    'reschedule_counter_proposed',
                    '🔄 Customer Counter-Proposed a Date',
                    "The customer proposed an alternative schedule for booking {$booking->booking_no}.\n\n" .
                        "📅 New Date: {$newDate->format('F d, Y')}\n" .
                        "⏰ New Time: " . ($validated['new_time'] ?? '—') . "\n\n" .
                        "Please review and approve or reject.",
                    \App\Models\Notification::PRIORITY_HIGH,
                    [
                        'booking_id' => $booking->booking_id,
                        'booking_no' => $booking->booking_no,
                    ],
                    "/admin/bookings/{$booking->booking_id}"
                );
            } catch (\Throwable $e) {
                Log::warning('Admin counter notification failed: ' . $e->getMessage());
            }

            return $this->ok($this->formatBooking($booking->fresh()), 'Counter proposal sent.');
        } catch (ValidationException $e) {
            return $this->fail($e->validator->errors()->first(), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('counterReschedule error: ' . $e->getMessage());
            return $this->fail('Failed to send counter proposal: ' . $e->getMessage(), 500);
        }
    }

    public function declineAdminReschedule(Request $request, Booking $booking): JsonResponse
    {
        try {
            if ($booking->reschedule_status !== 'pending' || $booking->reschedule_proposed_by !== 'admin') {
                return $this->fail('No pending admin reschedule proposal.', 422);
            }

            $validated = $request->validate([
                'reason' => ['nullable', 'string', 'max:500'],
            ]);

            DB::transaction(function () use ($booking, $validated) {
                $booking->update([
                    'booking_status'         => 'cancelled',
                    'cancellation_reason'    => $validated['reason'] ?? 'Customer declined reschedule.',
                    'reschedule_status'      => 'rejected',
                    'reschedule_proposed_by' => 'admin',
                ]);
                $booking->serviceEvent?->update(['status' => 'cancelled']);
            });

            $this->logCustom(
                'reschedule_declined_by_customer',
                'bookings',
                $booking->booking_id,
                "Customer declined reschedule and cancelled {$booking->booking_no}",
                ['booking_no' => $booking->booking_no]
            );

            return $this->ok($this->formatBooking($booking->fresh()), 'Booking cancelled.');
        } catch (ValidationException $e) {
            return $this->fail($e->validator->errors()->first(), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('declineAdminReschedule error: ' . $e->getMessage());
            return $this->fail('Failed to decline reschedule: ' . $e->getMessage(), 500);
        }
    }

    public function approveCustomerReschedule(Booking $booking): JsonResponse
    {
        try {
            if (
                $booking->reschedule_status !== 'pending' ||
                $booking->reschedule_proposed_by !== 'customer'
            ) {
                return $this->fail('No pending customer reschedule request.', 422);
            }

            $newDate = $booking->requested_date ? Carbon::parse($booking->requested_date) : null;
            if (!$newDate) {
                return $this->fail('Missing requested date.', 422);
            }

            $error = $this->validateRescheduleDate($newDate, $booking);
            if ($error) {
                return $this->fail($error['message'], 422, ['code' => $error['code']]);
            }

            $booking->loadMissing('serviceEvent');

            DB::transaction(function () use ($booking, $newDate) {
                $booking->serviceEvent?->update([
                    'event_date' => $newDate->toDateString(),
                    'event_time' => $booking->requested_time ?? $booking->serviceEvent->event_time,
                ]);
                $booking->update([
                    'booking_status'         => 'confirmed',
                    'reschedule_status'      => 'accepted',
                    'reschedule_proposed_by' => 'customer',
                    'requested_date'         => null,
                    'requested_time'         => null,
                    'reschedule_reason'      => null,
                    'reschedule_proposed_at' => null,
                    'original_event_date'    => null,
                    'original_event_time'    => null,
                ]);
            });

            $this->logCustom(
                'customer_reschedule_approved',
                'bookings',
                $booking->booking_id,
                "Admin approved customer reschedule for {$booking->booking_no}",
                [
                    'booking_no' => $booking->booking_no,
                    'new_date'   => $newDate->toDateString(),
                ]
            );

            try {
                $customer = $booking->serviceEvent?->customer;
                if ($customer?->user_id) {
                    app(NotificationService::class)->notifyUser(
                        $customer->user_id,
                        'reschedule_approved',
                        '✅ Reschedule Approved',
                        "Your reschedule request for booking {$booking->booking_no} has been approved.\n\n" .
                            "New date: {$newDate->format('F d, Y')}",
                        \App\Models\Notification::PRIORITY_HIGH,
                        ['booking_id' => $booking->booking_id],
                        "/customer/bookings/{$booking->booking_id}"
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Customer reschedule approval notification failed: ' . $e->getMessage());
            }

            return $this->ok($this->formatBooking($booking->fresh()), 'Reschedule approved.');
        } catch (\Throwable $e) {
            Log::error('approveCustomerReschedule error: ' . $e->getMessage());
            return $this->fail('Failed to approve reschedule: ' . $e->getMessage(), 500);
        }
    }

    public function rejectCustomerReschedule(Request $request, Booking $booking): JsonResponse
    {
        try {
            if (
                $booking->reschedule_status !== 'pending' ||
                $booking->reschedule_proposed_by !== 'customer'
            ) {
                return $this->fail('No pending customer reschedule request.', 422);
            }

            $validated = $request->validate([
                'reason' => ['nullable', 'string', 'max:500'],
            ]);

            $booking->update([
                'booking_status'         => 'reschedule_rejected',
                'reschedule_status'      => 'rejected',
                'reschedule_reason'      => $validated['reason'] ?? $booking->reschedule_reason,
                'reschedule_proposed_by' => 'customer',
            ]);

            $this->logCustom(
                'customer_reschedule_rejected',
                'bookings',
                $booking->booking_id,
                "Admin rejected customer reschedule for {$booking->booking_no}",
                [
                    'booking_no' => $booking->booking_no,
                    'reason'     => $validated['reason'] ?? null,
                ]
            );

            try {
                $customer = $booking->serviceEvent?->customer;
                if ($customer?->user_id) {
                    app(NotificationService::class)->notifyUser(
                        $customer->user_id,
                        'reschedule_rejected',
                        'Reschedule Not Approved',
                        "We could not accommodate your reschedule request for booking {$booking->booking_no}.\n\n" .
                            ($validated['reason'] ? "Reason: {$validated['reason']}\n\n" : '') .
                            "Please choose whether to continue with the original date or cancel the booking.",
                        \App\Models\Notification::PRIORITY_HIGH,
                        ['booking_id' => $booking->booking_id],
                        "/customer/bookings/{$booking->booking_id}"
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Customer reschedule rejection notification failed: ' . $e->getMessage());
            }

            return $this->ok(
                $this->formatBooking($booking->fresh()),
                'Reschedule rejected. Customer can decide to continue or cancel.'
            );
        } catch (ValidationException $e) {
            return $this->fail($e->validator->errors()->first(), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('rejectCustomerReschedule error: ' . $e->getMessage());
            return $this->fail('Failed to reject reschedule: ' . $e->getMessage(), 500);
        }
    }

    public function continueOriginalSchedule(Booking $booking): JsonResponse
    {
        try {
            if ($booking->booking_status !== 'reschedule_rejected') {
                return $this->fail('Booking is not in a rejected-reschedule state.', 422);
            }

            $booking->update([
                'booking_status'         => 'confirmed',
                'requested_date'         => null,
                'requested_time'         => null,
                'reschedule_status'      => null,
                'reschedule_proposed_by' => null,
                'reschedule_source'      => null,
                'original_event_date'    => null,
                'original_event_time'    => null,
            ]);

            $this->logCustom(
                'reschedule_customer_continue',
                'bookings',
                $booking->booking_id,
                "Customer chose to continue original schedule for {$booking->booking_no}",
                ['booking_no' => $booking->booking_no]
            );

            return $this->ok($this->formatBooking($booking->fresh()), 'Continuing with original schedule.');
        } catch (\Throwable $e) {
            Log::error('continueOriginalSchedule error: ' . $e->getMessage());
            return $this->fail('Failed to continue schedule: ' . $e->getMessage(), 500);
        }
    }

    public function cancelAfterRejectedReschedule(Request $request, Booking $booking): JsonResponse
    {
        try {
            if ($booking->booking_status !== 'reschedule_rejected') {
                return $this->fail('Booking is not in a rejected-reschedule state.', 422);
            }

            $validated = $request->validate([
                'reason' => ['nullable', 'string', 'max:500'],
            ]);

            DB::transaction(function () use ($booking, $validated) {
                $booking->update([
                    'booking_status'      => 'cancelled',
                    'cancellation_reason' => $validated['reason'] ?? 'Customer cancelled after rejected reschedule.',
                ]);
                $booking->serviceEvent?->update(['status' => 'cancelled']);
            });

            $this->logCustom(
                'reschedule_customer_cancelled',
                'bookings',
                $booking->booking_id,
                "Customer cancelled after rejected reschedule for {$booking->booking_no}",
                ['booking_no' => $booking->booking_no, 'reason' => $validated['reason'] ?? null]
            );

            return $this->ok($this->formatBooking($booking->fresh()), 'Booking cancelled.');
        } catch (ValidationException $e) {
            return $this->fail($e->validator->errors()->first(), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('cancelAfterRejectedReschedule error: ' . $e->getMessage());
            return $this->fail('Failed to cancel booking: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // CUSTOMER EDIT BOOKING + RESCHEDULE RESPONSE
    // ============================================================

    public function customerUpdate(Request $request, Booking $booking): JsonResponse
    {
        try {
            if (!in_array($booking->booking_status, ['pending_approval', 'pending'], true)) {
                return $this->fail('Booking can no longer be edited once it has been processed.', 403);
            }

            $validated = $request->validate([
                'venue'                   => ['nullable', 'string', 'max:500'],
                'guests_count'            => ['nullable', 'integer', 'min:10'],
                'event_date'              => ['nullable', 'date'],
                'event_time'              => ['nullable', 'string', 'max:50'],
                'special_requests'        => ['nullable', 'string', 'max:2000'],
                'delivery_address'        => ['nullable', 'string', 'max:500'],
                'delivery_contact_person' => ['nullable', 'string', 'max:150'],
                'delivery_contact_phone'  => ['nullable', 'string', 'max:30'],
            ]);

            $booking->loadMissing('serviceEvent');
            $old = [
                'venue'        => $booking->serviceEvent?->venue,
                'guests_count' => $booking->serviceEvent?->guests_count,
                'event_date'   => $booking->serviceEvent?->event_date?->toDateString(),
                'event_time'   => $booking->serviceEvent?->event_time,
            ];

            DB::transaction(function () use ($booking, $validated) {
                if ($booking->serviceEvent) {
                    $booking->serviceEvent->update(array_filter([
                        'venue'                   => $validated['venue'] ?? null,
                        'guests_count'            => $validated['guests_count'] ?? null,
                        'event_date'              => $validated['event_date'] ?? null,
                        'event_time'              => $validated['event_time'] ?? null,
                        'special_requests'        => $validated['special_requests'] ?? null,
                        'delivery_address'        => $validated['delivery_address'] ?? null,
                        'delivery_contact_person' => $validated['delivery_contact_person'] ?? null,
                        'delivery_contact_phone'  => $validated['delivery_contact_phone'] ?? null,
                    ], fn($v) => $v !== null));
                }
            });

            $this->logCustom(
                'customer_update',
                'bookings',
                $booking->booking_id,
                "Customer edited booking {$booking->booking_no}",
                ['old' => $old, 'new' => $validated]
            );

            try {
                app(NotificationService::class)->notifyRole(
                    'admin',
                    'booking_updated_by_customer',
                    '✏️ Booking Updated',
                    "Customer updated booking {$booking->booking_no}.\n" .
                        "Guests: " . ($booking->serviceEvent?->guests_count ?? '—') . "\n" .
                        "Venue: " . ($booking->serviceEvent?->venue ?? '—'),
                    \App\Models\Notification::PRIORITY_MEDIUM,
                    ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                    "/admin/bookings/{$booking->booking_id}"
                );
            } catch (\Throwable $e) {
                Log::warning('Customer edit notification failed: ' . $e->getMessage());
            }

            return $this->ok($this->formatBooking($booking->fresh()), 'Booking updated.');
        } catch (ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('Customer booking edit error: ' . $e->getMessage());
            return $this->fail('Failed to update booking: ' . $e->getMessage(), 500);
        }
    }

    public function customerRescheduleResponse(Request $request, Booking $booking): JsonResponse
    {
        try {
            if (
                $booking->reschedule_status !== 'pending' ||
                $booking->reschedule_proposed_by !== 'admin'
            ) {
                return $this->fail('No pending admin reschedule proposal for this booking.', 422);
            }

            $validated = $request->validate([
                'action'   => ['required', 'in:accept,counter,cancel'],
                'new_date' => ['nullable', 'date'],
                'new_time' => ['nullable', 'string', 'max:50'],
                'reason'   => ['nullable', 'string', 'max:500'],
            ]);

            $action = $validated['action'];

            if ($action === 'accept') {
                return $this->acceptAdminReschedule($booking);
            }

            if ($action === 'counter') {
                $req = new Request([
                    'new_date' => $validated['new_date'],
                    'new_time' => $validated['new_time'] ?? null,
                    'reason'   => $validated['reason'] ?? 'Customer proposed an alternative date.',
                ]);
                return $this->counterReschedule($req, $booking);
            }

            $req = new Request([
                'reason' => $validated['reason'] ?? 'Customer declined reschedule.',
            ]);
            return $this->declineAdminReschedule($req, $booking);
        } catch (ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('customerRescheduleResponse error: ' . $e->getMessage());
            return $this->fail('Failed to process response: ' . $e->getMessage(), 500);
        }
    }

    public function customerPostRejectionDecision(Request $request, Booking $booking): JsonResponse
    {
        try {
            if ($booking->booking_status !== 'reschedule_rejected') {
                return $this->fail('Booking is not awaiting a post-rejection decision.', 422);
            }

            $validated = $request->validate([
                'action' => ['required', 'in:continue,cancel'],
                'reason' => ['nullable', 'string', 'max:500'],
            ]);

            if ($validated['action'] === 'continue') {
                return $this->continueOriginalSchedule($booking);
            }

            $req = new Request([
                'reason' => $validated['reason'] ?? 'Customer cancelled after rejected reschedule.',
            ]);
            return $this->cancelAfterRejectedReschedule($req, $booking);
        } catch (ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('customerPostRejectionDecision error: ' . $e->getMessage());
            return $this->fail('Failed to process decision: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // AVAILABILITY VALIDATION ENDPOINT
    // ============================================================

    public function validateSlot(Request $request, BookingService $service): JsonResponse
    {
        try {
            $validated = $request->validate([
                'event_date'         => ['required', 'date'],
                'event_time'         => ['nullable', 'string', 'max:50'],
                'exclude_booking_id' => ['nullable', 'integer', 'exists:bookings,booking_id'],
            ]);

            $conflict = $service->validateAvailability(
                $validated['event_date'],
                $validated['event_time'] ?? null,
                $validated['exclude_booking_id'] ?? null
            );

            $sameDateTime = false;
            if (!empty($validated['exclude_booking_id'])) {
                $booking = Booking::with('serviceEvent')->find($validated['exclude_booking_id']);
                if ($booking?->serviceEvent) {
                    $sameDateTime = $booking->serviceEvent->event_date?->toDateString() === $validated['event_date']
                        && $booking->serviceEvent->event_time === ($validated['event_time'] ?? null);
                }
            }

            return $this->ok([
                'available'           => $conflict === null || $sameDateTime,
                'same_datetime'       => $sameDateTime,
                'requires_admin'      => $sameDateTime,
                'conflict'            => $conflict,
            ], $conflict === null || $sameDateTime
                ? 'Date/time is available.'
                : $conflict['message']);
        } catch (ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('Validate slot error: ' . $e->getMessage());
            return $this->fail('Failed to validate slot: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // ADMIN RESCHEDULE WITH AVAILABILITY CHECK
    // ============================================================

    public function adminRescheduleWithValidation(Request $request, Booking $booking, BookingService $service): JsonResponse
    {
        try {
            $validated = $request->validate([
                'new_date' => ['required', 'date'],
                'new_time' => ['required', 'string', 'max:50'],
                'reason'   => ['required', 'string', 'max:500'],
            ]);

            $booking->loadMissing('serviceEvent');
            $currentDate = $booking->serviceEvent?->event_date?->toDateString();
            $currentTime = $booking->serviceEvent?->event_time;

            $isSameDateTime = ($currentDate === $validated['new_date'])
                && ($currentTime === $validated['new_time']);

            $conflict = $service->validateAvailability(
                $validated['new_date'],
                $validated['new_time'],
                $booking->booking_id
            );

            if ($conflict && !$isSameDateTime) {
                return $this->fail($conflict['message'], 422, ['code' => $conflict['code']]);
            }

            $oldEventDate = $booking->serviceEvent?->event_date?->toDateString();
            $oldEventTime = $booking->serviceEvent?->event_time;

            DB::transaction(function () use ($booking, $validated, $oldEventDate, $oldEventTime, $isSameDateTime) {
                $booking->update([
                    'booking_status'         => $booking->booking_status ?: 'confirmed',
                    'requested_date'         => $validated['new_date'],
                    'requested_time'         => $validated['new_time'],
                    'reschedule_reason'      => $validated['reason'],
                    'reschedule_proposed_by' => 'admin',
                    'reschedule_status'      => 'pending',
                    'reschedule_source'      => 'admin_proposal',
                    'reschedule_proposed_at' => now(),
                    'original_event_date'    => $oldEventDate,
                    'original_event_time'    => $oldEventTime,
                ]);
            });

            $this->logCustom(
                'admin_reschedule_proposed',
                'bookings',
                $booking->booking_id,
                "Admin proposed reschedule for {$booking->booking_no}" . ($isSameDateTime ? ' (SAME DATE/TIME — admin override)' : ''),
                [
                    'booking_no'      => $booking->booking_no,
                    'new_date'        => $validated['new_date'],
                    'new_time'        => $validated['new_time'],
                    'reason'          => $validated['reason'],
                    'original_date'   => $oldEventDate,
                    'original_time'   => $oldEventTime,
                    'same_datetime'   => $isSameDateTime,
                ]
            );

            try {
                app(NotificationService::class)->rescheduleProposedByAdmin(
                    $booking->fresh(),
                    \Carbon\Carbon::parse($validated['new_date']),
                    $validated['new_time'],
                    $validated['reason']
                );
            } catch (\Throwable $e) {
                Log::warning('Admin reschedule notification failed: ' . $e->getMessage());
            }

            return $this->ok(
                $this->formatBooking($booking->fresh()),
                'Reschedule proposal sent to customer.'
            );
        } catch (ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('Admin reschedule error: ' . $e->getMessage());
            return $this->fail('Failed to propose reschedule: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // CUSTOMER RESCHEDULE WITH AVAILABILITY CHECK
    // ============================================================

    public function customerRequestRescheduleWithValidation(Request $request, Booking $booking, BookingService $service): JsonResponse
    {
        try {
            $validated = $request->validate([
                'requested_date' => ['required', 'date'],
                'requested_time' => ['required', 'string', 'max:50'],
                'reason' => ['required', 'string', 'max:500'],
            ]);

            $conflict = $service->validateAvailability(
                $validated['requested_date'],
                $validated['requested_time'],
                $booking->booking_id
            );

            if ($conflict) {
                return $this->fail($conflict['message'], 422, ['code' => $conflict['code']]);
            }

            // ⭐ Keep booking_status as confirmed — reschedule is a sub-state
            $restoreStatus = in_array($booking->booking_status, ['confirmed', 'ongoing'], true)
                ? $booking->booking_status
                : 'confirmed';

            $booking->update([
                'booking_status' => $restoreStatus,
                'requested_date' => $validated['requested_date'],
                'requested_time' => $validated['requested_time'],
                'reschedule_reason' => $validated['reason'],
                'reschedule_proposed_by' => 'customer',
                'reschedule_status' => 'pending',
                'reschedule_source' => 'customer_initial',
                'reschedule_proposed_at' => now(),
            ]);

            $this->logCustom(
                'customer_reschedule_requested',
                'bookings',
                $booking->booking_id,
                "Customer requested reschedule for {$booking->booking_no}",
                [
                    'booking_no' => $booking->booking_no,
                    'requested_date' => $validated['requested_date'],
                    'requested_time' => $validated['requested_time'],
                    'reason' => $validated['reason'],
                ]
            );

            try {
                app(NotificationService::class)->bookingRescheduleRequested(
                    $booking->fresh(),
                    $validated['requested_date'],
                    $validated['requested_time'],
                    $validated['reason']
                );
            } catch (\Throwable $e) {
                Log::warning('Customer reschedule notification failed: ' . $e->getMessage());
            }

            return $this->ok(
                $this->formatBooking($booking->fresh()),
                'Reschedule request sent to admin.'
            );
        } catch (ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('Customer reschedule error: ' . $e->getMessage());
            return $this->fail('Failed to request reschedule: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // CUSTOMER CANCELLATION WITH CUTOFF VALIDATION
    // ============================================================

    public function customerCancelWithValidation(Request $request, Booking $booking, BookingService $service): JsonResponse
    {
        try {
            $policy = app(\App\Services\BookingPolicyService::class);
            $cutoffDays = $policy->cancellationCutoffDays();
            $eventDate = $booking->serviceEvent?->event_date;

            if ($eventDate && $cutoffDays > 0) {
                $eventCarbon = $eventDate instanceof \Carbon\Carbon
                    ? $eventDate
                    : \Carbon\Carbon::parse($eventDate);

                $daysUntilEvent = (int) now()->startOfDay()->diffInDays(
                    $eventCarbon->copy()->startOfDay(),
                    false
                );

                if ($daysUntilEvent < $cutoffDays) {
                    return $this->fail(
                        'Cancellation is no longer available because the booking has reached the cancellation cutoff period.',
                        422,
                        [
                            'code' => 'cancellation_cutoff',
                            'cutoff_days' => $cutoffDays,
                            'days_until_event' => $daysUntilEvent,
                        ]
                    );
                }
            }

            $reason = $request->input('reason', 'Cancelled by customer.');

            $booking = $service->cancel($booking, $reason);

            $this->logCustom(
                'customer_cancelled',
                'bookings',
                $booking->booking_id,
                "Customer cancelled booking {$booking->booking_no}",
                [
                    'booking_no' => $booking->booking_no,
                    'reason' => $reason,
                    'cancelled_at' => now()->toDateTimeString(),
                ]
            );

            try {
                app(NotificationService::class)->bookingCancelled($booking, $reason);
            } catch (\Throwable $e) {
                Log::warning('Customer cancel notification failed: ' . $e->getMessage());
            }

            return $this->ok($this->formatBooking($booking->fresh()), 'Booking cancelled.');
        } catch (ValidationException $e) {
            return $this->fail($e->validator->errors()->first(), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('Customer cancel error: ' . $e->getMessage());
            return $this->fail('Failed to cancel booking: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // CUSTOMER RESPOND TO ADMIN RESCHEDULE
    // ============================================================

    public function customerRespondToAdminReschedule(Request $request, Booking $booking, BookingService $service): JsonResponse
    {
        try {
            if (
                $booking->reschedule_status !== 'pending' ||
                $booking->reschedule_proposed_by !== 'admin'
            ) {
                return $this->fail('No pending admin reschedule proposal for this booking.', 422);
            }

            $validated = $request->validate([
                'action' => ['required', 'in:accept,counter,cancel'],
                'new_date' => ['nullable', 'date'],
                'new_time' => ['nullable', 'string', 'max:50'],
                'reason' => ['nullable', 'string', 'max:500'],
            ]);

            $action = $validated['action'];

            if ($action === 'accept') {
                return $this->acceptAdminReschedule($booking);
            }

            if ($action === 'counter') {
                $request->validate([
                    'new_date' => ['required', 'date'],
                    'new_time' => ['required', 'string', 'max:50'],
                ]);

                $conflict = $service->validateAvailability(
                    $validated['new_date'],
                    $validated['new_time'],
                    $booking->booking_id
                );

                if ($conflict) {
                    return $this->fail($conflict['message'], 422, ['code' => $conflict['code']]);
                }

                // ⭐ Keep booking_status as confirmed — reschedule is a sub-state
                $restoreStatus = in_array($booking->booking_status, ['confirmed', 'ongoing'], true)
                    ? $booking->booking_status
                    : 'confirmed';

                $booking->update([
                    'booking_status' => $restoreStatus,
                    'requested_date' => $validated['new_date'],
                    'requested_time' => $validated['new_time'],
                    'reschedule_reason' => $validated['reason'] ?? 'Customer proposed an alternative date.',
                    'reschedule_proposed_by' => 'customer',
                    'reschedule_status' => 'pending',
                    'reschedule_source' => 'customer_counter',
                    'reschedule_proposed_at' => now(),
                ]);

                $this->logCustom(
                    'customer_counter_proposed',
                    'bookings',
                    $booking->booking_id,
                    "Customer counter-proposed for {$booking->booking_no}",
                    [
                        'booking_no'    => $booking->booking_no,
                        'new_date'      => $validated['new_date'],
                        'new_time'      => $validated['new_time'],
                        'original_date' => $booking->original_event_date,
                        'original_time' => $booking->original_event_time,
                    ]
                );

                try {
                    app(NotificationService::class)->notifyRole(
                        'admin',
                        'reschedule_counter_proposed',
                        '🔄 Customer Counter-Proposed a Date',
                        "The customer proposed an alternative schedule for booking {$booking->booking_no}.\n\n" .
                            "📅 New Date: " . \Carbon\Carbon::parse($validated['new_date'])->format('F d, Y') . "\n" .
                            "⏰ New Time: {$validated['new_time']}\n\n" .
                            "Please review and approve or reject.",
                        \App\Models\Notification::PRIORITY_HIGH,
                        [
                            'booking_id' => $booking->booking_id,
                            'booking_no' => $booking->booking_no,
                        ],
                        "/admin/bookings/{$booking->booking_id}"
                    );
                } catch (\Throwable $e) {
                    Log::warning('Admin counter notification failed: ' . $e->getMessage());
                }

                return $this->ok($this->formatBooking($booking->fresh()), 'Counter proposal sent to admin.');
            }

            // cancel
            $booking->update([
                'booking_status' => 'cancelled',
                'cancellation_reason' => $validated['reason'] ?? 'Customer declined reschedule.',
                'reschedule_status' => 'rejected',
                'reschedule_proposed_by' => 'admin',
            ]);
            $booking->serviceEvent?->update(['status' => 'cancelled']);

            return $this->ok($this->formatBooking($booking->fresh()), 'Booking cancelled.');
        } catch (ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('Customer reschedule response error: ' . $e->getMessage());
            return $this->fail('Failed to process response: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // PAYMENTS
    // ============================================================

    public function recordPayment(Request $request, Booking $booking): JsonResponse
    {
        try {
            $validated = $request->validate([
                'amount'           => ['required', 'numeric', 'min:0.01'],
                'method'           => ['nullable', 'string'],
                'payment_method'   => ['nullable', 'string'],
                'payment_type'     => ['nullable', 'in:deposit,partial,full'],
                'reference'        => ['nullable', 'string'],
                'reference_number' => ['nullable', 'string'],
                'notes'            => ['nullable', 'string'],
            ]);

            $method = strtolower(str_replace(' ', '_', $validated['payment_method'] ?? $validated['method'] ?? 'cash'));
            $allowedMethods = ['cash', 'gcash', 'maya', 'bank_transfer', 'card', 'check'];

            if (! in_array($method, $allowedMethods, true)) {
                $method = 'cash';
            }

            $booking->loadMissing(['invoice', 'quotation']);

            // ⭐ Auto-create the invoice the first time a payment is attempted.
            //    This makes "Pay Deposit" work directly from Order & Events
            //    without forcing the cashier to visit Billing & Invoicing first.
            $invoice = $booking->invoice;
            if (! $invoice) {
                $invoice = DB::transaction(function () use ($booking) {
                    return $this->createInvoiceForBooking($booking);
                });
                $booking->setRelation('invoice', $invoice);
            }

            // ⭐ Auto-detect the correct payment_type.
            //    A first payment >= 30% of the total is treated as a DEPOSIT.
            $invoiceTotal    = (float) ($invoice->total_amount ?? 0);
            $invoicePaid     = (float) ($invoice->paid_amount ?? 0);
            $invoiceBalance  = max(0, $invoiceTotal - $invoicePaid);
            $requiredDeposit = round($invoiceTotal * 0.30, 2);

            // ⭐ Reject payments on a fully settled invoice.
            if ($invoiceBalance <= 0.01 && $invoiceTotal > 0) {
                return $this->fail(
                    'This booking is already fully paid. Additional payment is not allowed.',
                    422,
                    ['code' => 'fully_paid']
                );
            }

            $isFirstPayment = ! BookingPayment::where('booking_id', $booking->booking_id)
                ->where('status', 'completed')
                ->where('payment_type', '!=', 'refund')
                ->exists();
            $resolvedPaymentType = $validated['payment_type'] ?? null;
            if (! $resolvedPaymentType) {
                if ((float) $validated['amount'] >= $invoiceBalance) {
                    $resolvedPaymentType = 'full';
                } elseif (
                    $isFirstPayment &&
                    $requiredDeposit > 0 &&
                    (float) $validated['amount'] >= ($requiredDeposit - 1.00)
                ) {
                    $resolvedPaymentType = 'deposit';
                } else {
                    $resolvedPaymentType = 'partial';
                }
            }

            // ⭐ Reject any payment above the remaining balance.
            if ((float) $validated['amount'] > $invoiceBalance + 0.01) {
                return $this->fail(
                    'Payment amount cannot exceed the remaining balance.',
                    422,
                    [
                        'code' => 'overpayment',
                        'remaining_balance' => $invoiceBalance,
                        'submitted_amount'  => (float) $validated['amount'],
                    ]
                );
            }

            $payment = DB::transaction(function () use ($booking, $validated, $method, $resolvedPaymentType) {
                return BookingPayment::create([
                    'booking_id'       => $booking->booking_id,
                    // ⭐ 4-digit sequential format — same as Invoice & Mobile payment.
                    'payment_number'   => $this->nextPaymentNumber(),
                    'amount'           => $validated['amount'],
                    'payment_method'   => $method,
                    'payment_type'     => $resolvedPaymentType,
                    'reference_number' => $validated['reference_number'] ?? $validated['reference'] ?? null,
                    'notes'            => $validated['notes'] ?? null,
                    'status'           => 'completed',
                    'payment_date'     => now(),
                    'verified_by'      => auth()->id(),
                    'verified_at'      => now(),
                ]);
            });

            // ⭐ Sync AFTER commit so the just-inserted row is visible to the
            //    aggregation and any sync failure does NOT roll back the payment.
            try {
                $this->synchronizeBookingInvoice($booking);
            } catch (\Throwable $e) {
                Log::error('synchronizeBookingInvoice failed after commit', [
                    'payment_id' => $payment->payment_id,
                    'booking_id' => $booking->booking_id,
                    'error'      => $e->getMessage(),
                ]);
            }
            $this->logCustom(
                'payment_recorded',
                'booking_payments',
                $payment->payment_id,
                "Payment of ₱{$validated['amount']} recorded for booking {$booking->booking_no}",
                [
                    'booking_id'       => $booking->booking_id,
                    'booking_no'       => $booking->booking_no,
                    'customer'         => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                    'amount'           => $validated['amount'],
                    'payment_method'   => $method,
                    'payment_type'     => $validated['payment_type'] ?? 'partial',
                    'reference_number' => $validated['reference_number'] ?? null,
                    'recorded_at'      => now()->toDateTimeString(),
                ]
            );

            $customer = $booking->serviceEvent?->customer;
            if ($customer && $customer->user_id) {
                app(NotificationService::class)->paymentReceived($payment, $customer);

                $balance = $booking->invoice?->balance ?? 0;
                if ($balance > 0) {
                    app(NotificationService::class)->balanceReminder($booking, $customer, $balance);
                }
            }

            app(NotificationService::class)->notifyRole(
                'admin',
                'payment_received',
                '💰 Payment Received',
                "Payment of ₱" . number_format($validated['amount'], 2) . " has been received for booking {$booking->booking_no}.",
                \App\Models\Notification::PRIORITY_HIGH,
                ['booking_id' => $booking->booking_id, 'amount' => $validated['amount']],
                "/admin/payments"
            );

            return $this->ok($payment, 'Payment recorded.');
        } catch (\Exception $e) {
            Log::error('Record payment error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to record payment: ' . $e->getMessage(), 500);
        }
    }

    public function paymentSummary(Booking $booking, BookingService $service): JsonResponse
    {
        return $this->ok($service->paymentSummary($booking));
    }

    // ============================================================
    // CALENDAR / CONFLICTS / STATISTICS
    // ============================================================

    public function calendar(): JsonResponse
    {
        try {
            $events = $this->query()
                ->where('booking_no', 'not like', 'HIST-%')
                ->whereIn('booking_status', ['confirmed', 'rescheduled', 'completed'])
                ->get()
                ->map(function (Booking $booking): array {
                    $formatted = $this->formatBooking($booking);
                    $endDate = $formatted['event_date'];
                    if ($formatted['days'] > 1) {
                        $start = Carbon::parse($formatted['event_date']);
                        $endDate = $start->copy()->addDays($formatted['days'] - 1)->toDateString();
                    }
                    return [
                        'id'    => $booking->booking_id,
                        'title' => $booking->booking_no . ' - ' . $formatted['customer_name'],
                        'start' => $formatted['event_date'],
                        'end'   => $endDate,
                        'status' => $booking->booking_status,
                        'extendedProps' => [
                            'event_time'   => $formatted['event_time'],
                            'venue'        => $formatted['venue'],
                            'service_type' => $formatted['service_type'],
                            'days'         => $formatted['days'],
                            'is_multi_day' => $formatted['days'] > 1,
                        ],
                    ];
                });
            return $this->ok($events);
        } catch (\Exception $e) {
            Log::error('Calendar error: ' . $e->getMessage());
            return $this->fail('Failed to load calendar events: ' . $e->getMessage(), 500);
        }
    }

    public function conflicts(Request $request): JsonResponse
    {
        try {
            $date = $request->input('event_date');
            if (! $date) {
                return $this->ok(['has_conflicts' => false, 'conflicts' => []]);
            }

            $bookings = $this->query()
                ->whereHas('serviceEvent', fn($query) => $query->whereDate('event_date', $date))
                ->get()
                ->map(fn(Booking $booking) => $this->formatBooking($booking));

            return $this->ok([
                'has_conflicts' => $bookings->count() > 1,
                'conflicts'     => $bookings->count() > 1
                    ? [['date' => $date, 'bookings' => $bookings->values()]]
                    : [],
            ]);
        } catch (\Exception $e) {
            Log::error('Conflicts check error: ' . $e->getMessage());
            return $this->fail('Failed to check conflicts: ' . $e->getMessage(), 500);
        }
    }

    public function checkConflictsAndNotify(Request $request): JsonResponse
    {
        try {
            $date = $request->input('event_date');
            if (!$date) {
                return $this->ok(['has_conflicts' => false]);
            }

            $conflicts = $this->query()
                ->whereHas('serviceEvent', fn($q) => $q->whereDate('event_date', $date))
                ->whereIn('booking_status', ['pending_approval', 'confirmed'])
                ->get();

            if ($conflicts->count() > 1) {
                app(NotificationService::class)->scheduleConflictWarning($date, $conflicts->toArray());

                $this->logCustom(
                    'conflict_detected',
                    'bookings',
                    null,
                    "Schedule conflict detected on {$date}",
                    [
                        'date'           => $date,
                        'conflict_count' => $conflicts->count(),
                        'booking_ids'    => $conflicts->pluck('booking_id')->toArray(),
                        'detected_at'    => now()->toDateTimeString(),
                    ]
                );
            }

            return $this->ok([
                'has_conflicts' => $conflicts->count() > 1,
                'conflicts'     => $conflicts,
            ]);
        } catch (\Exception $e) {
            Log::error('Check conflicts notify error: ' . $e->getMessage());
            return $this->fail('Failed to check conflicts: ' . $e->getMessage(), 500);
        }
    }
    public function statistics(Request $request): JsonResponse
    {
        try {
            $period = $request->input('period', 'monthly');
            $anchor = $request->input('anchor', now()->toDateString());

            [$start, $end, $label] = $this->resolvePeriod($period, $anchor);

            // ── Operational rows (confirmed / approved / rescheduled / ongoing)
            //    scoped to the selected period by service_events.event_date.
            $query = $this->query()
                ->where('booking_no', 'not like', 'HIST-%')
                ->whereIn('booking_status', ['confirmed', 'approved', 'rescheduled', 'ongoing']);
            $query = $this->applyPeriodFilter($query, $start, $end);

            $operational = $query->get()->map(fn(Booking $booking) => $this->formatBooking($booking));

            // ⭐ Confirmed Bookings KPI — same set the frontend shows.
            $confirmedRows = $operational->filter(function ($row) {
                $status = strtolower((string) ($row['booking_status'] ?? ''));
                return in_array($status, ['confirmed', 'approved', 'rescheduled', 'ongoing'], true)
                    && empty($row['event_completed']);
            });

            $confirmedCount = $confirmedRows->count();
            // ⭐ FIX #1 — Pending approvals are NOT part of $operational
            // (it is filtered to confirmed/approved/rescheduled/ongoing),
            // so count them from a separate query.
            //
            // ⭐ CRITICAL: do NOT apply the period filter here.
            // A booking is "pending" because it still needs admin action
            // right now — regardless of when its event is scheduled.
            // Filtering by period would hide pending bookings whose event
            // date falls outside the currently selected month/week/year.
            $pendingApprovalsCount = (int) $this->query()
                ->where('booking_no', 'not like', 'HIST-%')
                ->whereIn('booking_status', ['pending', 'pending_approval', 'draft'])
                ->count();
            // ⭐ Total Revenue KPI — sum of total_amount on the confirmed set.
            $totalRevenue = $confirmedRows->sum('total_amount');

            // ⭐ Outstanding Balance KPI — sum of balance on the confirmed set,
            //    derived from total - paid when the API didn't send balance.
            $outstandingBalance = $confirmedRows->sum(function ($row) {
                $total = (float) ($row['total_amount'] ?? 0);
                $paid  = (float) ($row['paid_amount']  ?? 0);
                $bal   = $row['balance'] ?? null;
                return $bal !== null ? (float) $bal : max(0, $total - $paid);
            });

            // ── Payment totals (all bookings in the period, incl. history),
            //    refunds excluded so the value reflects money retained.
            $allQuery = $this->query()
                ->where('booking_no', 'not like', 'HIST-%');
            $allQuery = $this->applyPeriodFilter($allQuery, $start, $end);

            $allRows = $allQuery->with(['serviceEvent', 'payments', 'quotation', 'invoice'])->get();

            $totalPaymentsCollected = $allRows->sum(function (Booking $booking) {
                $payments = $booking->relationLoaded('payments') ? $booking->payments : collect();

                $fromList = $payments
                    ->filter(fn($p) => strtolower((string) $p->status) === 'completed')
                    ->filter(fn($p) => strtolower((string) $p->payment_type) !== 'refund')
                    ->sum('amount');

                if ($fromList > 0) {
                    return (float) $fromList;
                }
                return 0.0;
            });

            return $this->ok([
                'period'                   => $period,
                'period_label'             => $label,
                'period_start'             => $start?->toDateString(),
                'period_end'               => $end?->toDateString(),

                'total_bookings'           => $operational->count(),
                'pending_approvals'        => $pendingApprovalsCount,
                'confirmed_bookings'       => $confirmedCount,
                'completed_bookings'       => $operational->where('booking_status', 'completed')->count(),
                'regular_bookings'         => $operational->where('days', '<=', 1)->count(),
                'multi_day_events'         => $operational->where('days', '>', 1)->count(),

                'total_revenue'            => $totalRevenue,
                'total_paid'               => $totalPaymentsCollected,
                'total_payments_collected' => $totalPaymentsCollected,
                'total_outstanding'        => $outstandingBalance,
                'outstanding_balance'      => $outstandingBalance,
            ]);
        } catch (\Exception $e) {
            Log::error('Statistics error: ' . $e->getMessage());
            return $this->fail('Failed to load statistics: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // AVAILABILITY
    // ============================================================

    public function getAvailability(Request $request): JsonResponse
    {
        try {
            $startDate = $request->input('start_date', now()->toDateString());
            $endDate   = $request->input('end_date', now()->addMonths(3)->toDateString());

            try {
                $start = Carbon::parse($startDate);
                $end   = Carbon::parse($endDate);
            } catch (\Exception $e) {
                return $this->fail('Invalid date format. Use YYYY-MM-DD.', 422);
            }

            if ($start->diffInMonths($end) > 12) {
                $end = $start->copy()->addMonths(12);
            }

            $availability = [];
            $current = clone $start;

            while ($current <= $end) {
                $date = $current->toDateString();

                try {
                    $bookingCount = Booking::whereIn('booking_status', ['confirmed', 'pending_approval'])
                        ->whereHas('serviceEvent', function ($query) use ($date) {
                            $query->whereDate('event_date', $date);
                        })->count();

                    $setting = Setting::where('group', 'booking_calendar')
                        ->where('key', $date)
                        ->first();

                    $status        = 'available';
                    $operationMode = 'normal';
                    $maxBookings   = null;
                    $notes         = null;
                    $isHoliday     = false;

                    if ($setting) {
                        try {
                            $value = json_decode($setting->value, true);
                            if (is_array($value)) {
                                $status        = $value['status'] ?? 'available';
                                $operationMode = $value['operation_mode'] ?? (!empty($value['max_bookings']) ? 'limited_slot' : 'normal');
                                $maxBookings   = $operationMode === 'limited_slot' ? ($value['max_bookings'] ?? null) : null;
                                $notes         = $value['notes'] ?? null;
                                $isHoliday     = $value['is_holiday'] ?? false;
                            }
                        } catch (\Exception $e) {
                            Log::warning('Failed to decode setting value', [
                                'date'  => $date,
                                'value' => $setting->value,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    $isPast        = $current->isPast() && !$current->isToday();
                    $isLimitedSlot = $operationMode === 'limited_slot' && $maxBookings !== null;
                    $isAvailable   = !$isPast && $status === 'available' && (! $isLimitedSlot || $bookingCount < $maxBookings);

                    $availability[] = [
                        'date'           => $date,
                        'status'         => $isPast ? 'past' : $status,
                        'operation_mode' => $operationMode,
                        'booking_count'  => $bookingCount,
                        'max_bookings'   => $maxBookings,
                        'is_available'   => $isAvailable,
                        'notes'          => $notes,
                        'day_of_week'    => $current->format('l'),
                        'formatted'      => $current->format('F j, Y'),
                        'is_holiday'     => $isHoliday,
                        'is_past'        => $isPast,
                        'is_today'       => $current->isToday(),
                    ];
                } catch (\Exception $e) {
                    Log::error('Error processing date: ' . $date, [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    $availability[] = [
                        'date'           => $date,
                        'status'         => 'available',
                        'booking_count'  => 0,
                        'operation_mode' => 'normal',
                        'max_bookings'   => null,
                        'is_available'   => true,
                        'notes'          => null,
                        'day_of_week'    => $current->format('l'),
                        'formatted'      => $current->format('F j, Y'),
                        'is_holiday'     => false,
                        'is_past'        => $current->isPast() && !$current->isToday(),
                        'is_today'       => $current->isToday(),
                    ];
                }

                $current->addDay();
            }

            return $this->ok($availability);
        } catch (\Exception $e) {
            Log::error('Get availability critical error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return $this->fail('Failed to get availability: ' . $e->getMessage(), 500);
        }
    }

    public function getAvailableDates(Request $request): JsonResponse
    {
        try {
            $startDate = $request->input('start_date', now()->toDateString());
            $endDate   = $request->input('end_date', now()->addMonths(3)->toDateString());

            try {
                $start = Carbon::parse($startDate);
                $end   = Carbon::parse($endDate);
            } catch (\Exception $e) {
                return $this->fail('Invalid date format. Use YYYY-MM-DD.', 422);
            }

            if ($start->diffInMonths($end) > 12) {
                $end = $start->copy()->addMonths(12);
            }

            $availableDates = [];
            $current = clone $start;

            while ($current <= $end) {
                $date = $current->toDateString();

                if ($current->isPast() && !$current->isToday()) {
                    $current->addDay();
                    continue;
                }

                try {
                    $setting = Setting::where('group', 'booking_calendar')
                        ->where('key', $date)
                        ->first();

                    $isAvailable = true;
                    $notes       = null;

                    if ($setting) {
                        $value = json_decode($setting->value, true);
                        if (is_array($value)) {
                            $status        = $value['status'] ?? 'available';
                            $operationMode = $value['operation_mode'] ?? (!empty($value['max_bookings']) ? 'limited_slot' : 'normal');
                            $maxBookings   = $operationMode === 'limited_slot' ? ($value['max_bookings'] ?? null) : null;
                            $notes         = $value['notes'] ?? null;

                            $bookingCount = Booking::whereIn('booking_status', ['confirmed', 'pending_approval'])
                                ->whereHas('serviceEvent', function ($query) use ($date) {
                                    $query->whereDate('event_date', $date);
                                })->count();

                            $isLimitedSlot = $operationMode === 'limited_slot' && $maxBookings !== null;
                            $isAvailable   = $status === 'available' && (! $isLimitedSlot || $bookingCount < $maxBookings);
                        }
                    }

                    if ($isAvailable) {
                        $availableDates[] = [
                            'date'        => $date,
                            'day_of_week' => $current->format('l'),
                            'formatted'   => $current->format('F j, Y'),
                            'notes'       => $notes,
                        ];
                    }
                } catch (\Exception $e) {
                    Log::warning('Error checking availability for date: ' . $date, [
                        'error' => $e->getMessage(),
                    ]);
                }

                $current->addDay();
            }

            return $this->ok($availableDates);
        } catch (\Exception $e) {
            Log::error('Get available dates error: ' . $e->getMessage());
            return $this->fail('Failed to get available dates: ' . $e->getMessage(), 500);
        }
    }

    public function getAvailableTimeSlots(Request $request): JsonResponse
    {
        try {
            $date = $request->input('date', now()->toDateString());

            try {
                $dateObj = Carbon::parse($date);
            } catch (\Exception $e) {
                return $this->fail('Invalid date format. Use YYYY-MM-DD.', 422);
            }

            $allSlots = [
                ['value' => '08:00 AM', 'label' => '8:00 AM'],
                ['value' => '09:00 AM', 'label' => '9:00 AM'],
                ['value' => '10:00 AM', 'label' => '10:00 AM'],
                ['value' => '11:00 AM', 'label' => '11:00 AM'],
                ['value' => '12:00 PM', 'label' => '12:00 PM'],
                ['value' => '01:00 PM', 'label' => '1:00 PM'],
                ['value' => '02:00 PM', 'label' => '2:00 PM'],
                ['value' => '03:00 PM', 'label' => '3:00 PM'],
                ['value' => '04:00 PM', 'label' => '4:00 PM'],
                ['value' => '05:00 PM', 'label' => '5:00 PM'],
                ['value' => '06:00 PM', 'label' => '6:00 PM'],
                ['value' => '07:00 PM', 'label' => '7:00 PM'],
                ['value' => '08:00 PM', 'label' => '8:00 PM'],
            ];

            if ($dateObj->isToday()) {
                $currentHour = now()->format('h:i A');
                $allSlots = array_filter($allSlots, function ($slot) use ($currentHour) {
                    return strtotime($slot['value']) > strtotime($currentHour);
                });
                $allSlots = array_values($allSlots);
            }

            if ($dateObj->isPast() && !$dateObj->isToday()) {
                return $this->ok([]);
            }

            $conflictingBookings = Booking::whereIn('booking_status', ['confirmed', 'pending_approval'])
                ->whereHas('serviceEvent', function ($query) use ($date) {
                    $query->whereDate('event_date', $date);
                })->with('serviceEvent')->get();

            $bookedTimes = $conflictingBookings->pluck('serviceEvent.event_time')->filter()->toArray();

            $availableSlots = array_filter($allSlots, function ($slot) use ($bookedTimes) {
                return !in_array($slot['value'], $bookedTimes);
            });

            return $this->ok(array_values($availableSlots));
        } catch (\Exception $e) {
            Log::error('Get time slots error: ' . $e->getMessage());
            return $this->fail('Failed to get time slots: ' . $e->getMessage(), 500);
        }
    }

    public function saveAvailability(Request $request, string $date): JsonResponse
    {
        try {
            try {
                Carbon::parse($date);
            } catch (\Exception $e) {
                return $this->fail('Invalid date format. Use YYYY-MM-DD.', 422);
            }

            $validated = $request->validate([
                'status'         => ['required', 'in:available,fully_booked,unavailable'],
                'operation_mode' => ['nullable', 'in:normal,limited_slot'],
                'max_bookings'   => ['nullable', 'integer', 'min:1'],
                'notes'          => ['nullable', 'string', 'max:2000'],
            ]);

            if (Carbon::parse($date)->isPast() && !Carbon::parse($date)->isToday()) {
                return $this->fail('Cannot edit availability for past dates.', 422);
            }

            $operationMode = $validated['operation_mode'] ?? 'normal';
            if ($validated['status'] !== 'available') {
                $operationMode = 'normal';
            }
            if ($operationMode === 'limited_slot' && empty($validated['max_bookings'])) {
                return $this->fail('Maximum bookings limit is required when Limited Slot mode is selected.', 422);
            }

            $data = [
                'status'         => $validated['status'],
                'operation_mode' => $operationMode,
                'max_bookings'   => $operationMode === 'limited_slot' ? ($validated['max_bookings'] ?? null) : null,
                'notes'          => $validated['notes'] ?? null,
                'updated_at'     => now()->toDateTimeString(),
            ];

            Setting::updateOrCreate(
                ['group' => 'booking_calendar', 'key' => $date],
                ['value' => json_encode($data), 'type' => 'json']
            );

            $this->logCustom(
                'availability_update',
                'settings',
                null,
                "Calendar availability updated for {$date}",
                [
                    'date'           => $date,
                    'status'         => $validated['status'],
                    'operation_mode' => $operationMode,
                    'max_bookings'   => $data['max_bookings'] ?? null,
                    'updated_at'     => now()->toDateTimeString(),
                ]
            );

            return $this->ok(null, 'Calendar availability saved.');
        } catch (ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('Save availability error: ' . $e->getMessage());
            return $this->fail('Failed to save availability: ' . $e->getMessage(), 500);
        }
    }

    public function deleteAvailability(string $date): JsonResponse
    {
        try {
            try {
                Carbon::parse($date);
            } catch (\Exception $e) {
                return $this->fail('Invalid date format. Use YYYY-MM-DD.', 422);
            }

            if (Carbon::parse($date)->isPast() && !Carbon::parse($date)->isToday()) {
                return $this->fail('Cannot reset availability for past dates.', 422);
            }

            Setting::where('group', 'booking_calendar')->where('key', $date)->delete();

            $this->logCustom(
                'availability_reset',
                'settings',
                null,
                "Calendar availability reset for {$date}",
                ['date' => $date, 'reset_at' => now()->toDateTimeString()]
            );

            return $this->ok(null, 'Calendar availability reset.');
        } catch (\Exception $e) {
            Log::error('Delete availability error: ' . $e->getMessage());
            return $this->fail('Failed to reset availability: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // ORDER / EVENT CREATION
    // ============================================================

    public function createOrder(Booking $booking, BookingService $service): JsonResponse
    {
        try {
            $booking = $service->approve($booking);

            $this->logCustom(
                'order_created',
                'orders',
                $booking->order?->order_id,
                "Order #{$booking->order?->order_number} created from booking {$booking->booking_no}",
                [
                    'booking_no'   => $booking->booking_no,
                    'order_number' => $booking->order?->order_number,
                    'created_at'   => now()->toDateTimeString(),
                ]
            );

            return $this->ok($booking->order, 'Order created.');
        } catch (\Exception $e) {
            Log::error('Create order error: ' . $e->getMessage());
            return $this->fail('Failed to create order: ' . $e->getMessage(), 500);
        }
    }
    /**
     * ⭐ Un-start an event started by mistake.
     *
     * Reverts booking_status to 'confirmed' and clears the 'ongoing'
     * EventTracking row so the booking reappears in Confirmed Bookings.
     */
    public function unstartEvent(Request $request, Booking $booking): JsonResponse
    {
        try {
            $oldData = $booking->toArray();
            $booking->loadMissing('serviceEvent');

            $status = strtolower((string) $booking->booking_status);
            if ($status !== 'ongoing') {
                return $this->fail(
                    'Only ongoing events can be un-started.',
                    422
                );
            }

            DB::transaction(function () use ($booking) {
                $booking->update(['booking_status' => 'confirmed']);

                if (Schema::hasTable('event_tracking')) {
                    EventTracking::where('booking_id', $booking->booking_id)
                        ->where('stage', 'ongoing')
                        ->delete();
                }
            });

            $this->logCustom(
                'unstart_event',
                'bookings',
                $booking->booking_id,
                "Booking {$booking->booking_no} un-started (reverted to confirmed)",
                [
                    'booking_no'   => $booking->booking_no,
                    'old_status'   => $oldData['booking_status'] ?? 'unknown',
                    'new_status'   => 'confirmed',
                    'unstarted_at' => now()->toDateTimeString(),
                ]
            );

            return $this->ok(
                $this->formatBooking($booking->fresh()),
                'Event un-started and moved back to Confirmed Bookings.'
            );
        } catch (\Throwable $e) {
            Log::error('Un-start event error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->fail(
                'Failed to un-start event: ' . $e->getMessage(),
                500
            );
        }
    }

    public function complete(Booking $booking, BookingService $service): JsonResponse
    {
        try {
            $booking->loadMissing([
                'serviceEvent.customer.person',
                'payments',
                'invoice',
                'quotation',
                'order',
                'equipment.equipment',
            ]);

            $completionCheck = $this->validateBookingCompletion($booking);
            if (! $completionCheck['can_complete']) {
                return $this->fail(
                    'Cannot complete this booking. ' . implode(' ', $completionCheck['missing']),
                    422
                );
            }

            $oldData = $booking->toArray();

            app(\App\Services\EventService::class)->completeEvent(
                $booking,
                true,
                [
                    'debt_booking_event'  => false,
                    'outstanding_balance' => 0,
                    'approved_by'         => auth()->id(),
                    'approved_at'         => now()->toDateTimeString(),
                ]
            );

            $this->logCustom(
                'complete',
                'bookings',
                $booking->booking_id,
                "Booking {$booking->booking_no} marked as COMPLETED",
                [
                    'booking_no'          => $booking->booking_no,
                    'customer'            => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                    'old_status'          => $oldData['booking_status'] ?? 'pending',
                    'new_status'          => 'completed',
                    'completed_at'        => now()->toDateTimeString(),
                    'inventory_deduction' => 'Handled by EventService; duplicate deductions are prevented.',
                ]
            );

            $customer = $booking->serviceEvent?->customer;
            if ($customer && $customer->user_id) {
                app(NotificationService::class)->notifyUser(
                    $customer->user_id,
                    'event_completed',
                    'Event Completed',
                    "Your event {$booking->booking_no} has been completed successfully. Thank you for choosing us!",
                    \App\Models\Notification::PRIORITY_HIGH,
                    ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                    "/customer/bookings/{$booking->booking_id}"
                );
            }

            app(NotificationService::class)->notifyRole(
                'admin',
                'event_completed',
                '🎉 Event Completed',
                "Event for booking {$booking->booking_no} has been marked as completed.",
                \App\Models\Notification::PRIORITY_MEDIUM,
                ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                "/admin/events"
            );

            return $this->ok(
                $this->formatBooking($booking->fresh()),
                'Booking marked as completed.'
            );
        } catch (\Exception $e) {
            Log::error('Complete booking error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to complete booking: ' . $e->getMessage(), 500);
        }
    }

    public function createEvent(Booking $booking): JsonResponse
    {
        try {
            $event = $booking->serviceEvent;
            if (!$event) {
                return $this->fail('No service event found for this booking', 422);
            }

            $tracking = EventTracking::create([
                'booking_id'          => $booking->booking_id,
                'stage'               => 'preparation',
                'progress_percentage' => 0,
                'stage_started_at'    => now(),
                'notes'               => json_encode([
                    'created_from_booking' => $booking->booking_no,
                    'created_at'           => now()->toIso8601String(),
                ]),
            ]);

            return $this->ok([
                'booking_id' => $booking->booking_id,
                'event'      => $event,
                'tracking'   => $tracking,
            ], 'Event created successfully');
        } catch (\Exception $e) {
            Log::error('Create event error: ' . $e->getMessage());
            return $this->fail('Failed to create event: ' . $e->getMessage(), 500);
        }
    }

    public function cancelWithReason(Request $request, Booking $booking, BookingService $service): JsonResponse
    {
        try {
            $validated = $request->validate([
                'reason' => ['required', 'string', 'max:500'],
            ]);

            $oldData = $booking->toArray();
            $reason  = $validated['reason'] ?? null;

            $booking = $service->cancel($booking, $reason);

            foreach ($booking->items as $item) {
                if (!$item->menu_item_id) continue;
                foreach ($item->menuItem->recipeIngredients as $recipe) {
                    $stock = InventoryStock::where('ingredient_id', $recipe->ingredient_id)->first();
                    if ($stock) {
                        $requiredQty = $recipe->quantity_per_pax * $item->quantity;
                        $stock->decrement('reserved_quantity', $requiredQty);
                    }
                }
            }

            Setting::where('group', 'calendar_events')
                ->where('key', 'booking_' . $booking->booking_id)
                ->delete();

            $this->logCustom(
                'cancel_with_reason',
                'bookings',
                $booking->booking_id,
                "Booking {$booking->booking_no} CANCELLED with reason",
                [
                    'booking_no'   => $booking->booking_no,
                    'customer'     => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                    'reason'       => $reason,
                    'old_status'   => $oldData['booking_status'] ?? 'pending',
                    'new_status'   => 'cancelled',
                    'cancelled_at' => now()->toDateTimeString(),
                ]
            );

            app(NotificationService::class)->bookingCancelled($booking, $reason);

            return $this->ok(
                $this->formatBooking($booking->fresh()),
                'Booking cancelled.'
            );
        } catch (ValidationException $e) {
            return $this->fail(
                $e->validator->errors()->first('cancellation')
                    ?: $e->validator->errors()->first()
                    ?: 'Cancellation is not allowed at this time.',
                422,
                $e->errors()
            );
        } catch (\Exception $e) {
            Log::error('Cancel with reason error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to cancel booking: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // REFUND + DEPOSIT DECISION
    // ============================================================

    public function requestRefund(Request $request, Booking $booking): JsonResponse
    {
        try {
            $validated = $request->validate([
                'reason'         => ['required', 'string', 'max:500'],
                'cancel_booking' => ['nullable', 'boolean'],
            ]);

            $booking->loadMissing(['serviceEvent', 'payments', 'quotation', 'invoice']);

            $depositPaid = (float) $booking->payments()
                ->where('status', 'completed')
                ->where('payment_type', 'deposit')
                ->sum('amount');

            if ($depositPaid <= 0) {
                $depositPaid = (float) ($booking->invoice?->down_payment ?? 0);
            }

            $existingState = $booking->refund_request_state;
            if (($existingState['status'] ?? null) === 'pending') {
                return $this->fail('A refund request is already pending for this booking.', 422);
            }

            $refundState = [
                'status'           => 'pending',
                'amount'           => 0,
                'deposit_snapshot' => $depositPaid,
                'reason'           => $validated['reason'],
                'requested_at'     => now()->toIso8601String(),
                'requested_by'     => optional($request->user())->user_id,
                'decided_at'       => null,
                'decided_by'       => null,
                'decision_notes'   => null,
                'released_at'      => null,
                'released_amount'  => null,
                'payment_id'       => null,
            ];

            Setting::setValue(
                self::SETTINGS_GROUP_REFUND_REQUESTS,
                'booking_' . $booking->booking_id,
                $refundState,
                'json'
            );

            $cancellationRequested = (bool) ($validated['cancel_booking'] ?? false);
            if ($cancellationRequested) {
                $booking->update([
                    'cancellation_reason' => trim(($booking->cancellation_reason ?? '') .
                        "\n[Cancellation requested by cashier: " . $validated['reason'] . "]"),
                ]);
            }

            $this->logCustom(
                'refund_requested',
                'bookings',
                $booking->booking_id,
                "Refund requested for booking {$booking->booking_no}",
                [
                    'booking_no'       => $booking->booking_no,
                    'deposit_snapshot' => $depositPaid,
                    'reason'           => $validated['reason'],
                    'cancel_booking'   => $cancellationRequested,
                    'requested_at'     => $refundState['requested_at'],
                ]
            );

            try {
                app(NotificationService::class)->notifyRole(
                    'admin',
                    'refund_requested',
                    '💰 Refund Request Pending',
                    "A refund request has been submitted for booking {$booking->booking_no}.\n" .
                        "Deposit on file: ₱" . number_format($depositPaid, 2) . "\n" .
                        "Please review and input the refund amount to release.",
                    \App\Models\Notification::PRIORITY_HIGH,
                    [
                        'booking_id' => $booking->booking_id,
                        'booking_no' => $booking->booking_no,
                    ],
                    "/admin/bookings/{$booking->booking_id}"
                );
            } catch (\Throwable $notifyErr) {
                Log::warning('Refund request notification failed: ' . $notifyErr->getMessage());
            }

            return $this->ok($this->formatBooking($booking->fresh()), 'Refund request submitted. Awaiting admin approval.');
        } catch (ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('Refund request error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to submit refund request: ' . $e->getMessage(), 500);
        }
    }

    public function approveRefund(Request $request, Booking $booking): JsonResponse
    {
        try {
            $refundState = $booking->refund_request_state;

            if (($refundState['status'] ?? null) !== 'pending') {
                return $this->fail('No pending refund request for this booking.', 422);
            }

            $validated = $request->validate([
                'notes' => ['nullable', 'string', 'max:500'],
            ]);

            $refundState['status']         = 'approved';
            $refundState['approved_at']    = now()->toIso8601String();
            $refundState['decided_at']     = now()->toIso8601String();
            $refundState['decided_by']     = optional($request->user())->user_id;
            $refundState['decision_notes'] = $validated['notes'] ?? null;

            Setting::setValue(
                self::SETTINGS_GROUP_REFUND_REQUESTS,
                'booking_' . $booking->booking_id,
                $refundState,
                'json'
            );

            $this->logCustom(
                'refund_approved',
                'bookings',
                $booking->booking_id,
                "Refund APPROVED (awaiting cashier release) for booking {$booking->booking_no}",
                [
                    'booking_no'  => $booking->booking_no,
                    'notes'       => $validated['notes'] ?? null,
                    'approved_at' => $refundState['approved_at'],
                ]
            );

            try {
                app(NotificationService::class)->notifyRole(
                    'cashier',
                    'refund_approved',
                    '✅ Refund Request Approved',
                    "The refund request for booking {$booking->booking_no} was approved. " .
                        "Please open the booking and confirm the refund amount.",
                    \App\Models\Notification::PRIORITY_HIGH,
                    ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                    "/admin/bookings/{$booking->booking_id}"
                );
            } catch (\Throwable $notifyErr) {
                Log::warning('Cashier refund approval notification failed: ' . $notifyErr->getMessage());
            }

            $customer = $booking->serviceEvent?->customer;
            if ($customer && $customer->user_id) {
                try {
                    app(NotificationService::class)->notifyUser(
                        $customer->user_id,
                        'refund_approved',
                        '✅ Refund Approved',
                        "Your refund request for booking {$booking->booking_no} has been approved. " .
                            "The refund will be released shortly.",
                        \App\Models\Notification::PRIORITY_HIGH,
                        ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                        "/customer/bookings/{$booking->booking_id}"
                    );
                } catch (\Throwable $notifyErr) {
                    Log::warning('Customer refund approval notification failed: ' . $notifyErr->getMessage());
                }
            }

            return $this->ok($this->formatBooking($booking->fresh()), 'Refund approved. Awaiting cashier confirmation.');
        } catch (ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('Approve refund error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to approve refund: ' . $e->getMessage(), 500);
        }
    }

    public function confirmRefund(Request $request, Booking $booking): JsonResponse
    {
        try {
            $refundState = $booking->refund_request_state;

            if (($refundState['status'] ?? null) !== 'approved') {
                return $this->fail('No approved refund to confirm for this booking.', 422);
            }

            $validated = $request->validate([
                'refund_amount'    => ['required', 'numeric', 'min:0.01'],
                'payment_method'   => ['nullable', 'string', 'max:50'],
                'reference_number' => ['nullable', 'string', 'max:100'],
                'notes'            => ['nullable', 'string', 'max:500'],
            ]);

            $refundAmount = (float) $validated['refund_amount'];
            $method = strtolower(str_replace(' ', '_', $validated['payment_method'] ?? 'cash'));
            $allowedMethods = ['cash', 'gcash', 'maya', 'bank_transfer', 'card', 'check'];
            if (!in_array($method, $allowedMethods, true)) {
                $method = 'cash';
            }

            $booking->loadMissing(['invoice', 'payments']);

            $payment = DB::transaction(function () use ($booking, $refundAmount, $method, $validated) {
                $payment = BookingPayment::create([
                    'booking_id'       => $booking->booking_id,
                    'payment_number'   => 'REF-' . now()->format('YmdHisv') . '-' . $booking->booking_id . '-' . random_int(100, 999),
                    'amount'           => $refundAmount,
                    'payment_method'   => $method,
                    'payment_type'     => 'refund',
                    'reference_number' => $validated['reference_number'] ?? null,
                    'notes'            => trim('Refund released by cashier. ' . ($validated['notes'] ?? '')),
                    'status'           => 'completed',
                    'payment_date'     => now(),
                    'verified_by'      => auth()->id(),
                    'verified_at'      => now(),
                ]);

                $this->synchronizeBookingInvoice($booking);

                return $payment;
            });

            $refundState['status']           = 'released';
            $refundState['amount']           = $refundAmount;
            $refundState['released_amount']  = $refundAmount;
            $refundState['released_at']      = now()->toIso8601String();
            $refundState['released_by']      = optional($request->user())->user_id;
            $refundState['payment_id']       = $payment->payment_id;
            $refundState['payment_method']   = $method;
            $refundState['reference_number'] = $validated['reference_number'] ?? null;

            Setting::setValue(
                self::SETTINGS_GROUP_REFUND_REQUESTS,
                'booking_' . $booking->booking_id,
                $refundState,
                'json'
            );

            $booking->update([
                'booking_status'      => 'cancelled',
                'cancellation_reason' => trim('Refund released by cashier. ' . ($validated['notes'] ?? '')),
            ]);
            $booking->serviceEvent?->update(['status' => 'cancelled']);

            $this->logCustom(
                'refund_released',
                'bookings',
                $booking->booking_id,
                "Refund RELEASED by cashier for booking {$booking->booking_no}",
                [
                    'booking_no'       => $booking->booking_no,
                    'refund_amount'    => $refundAmount,
                    'payment_method'   => $method,
                    'reference_number' => $validated['reference_number'] ?? null,
                    'payment_id'       => $payment->payment_id,
                    'released_at'      => $refundState['released_at'],
                ]
            );

            $customer = $booking->serviceEvent?->customer;
            if ($customer && $customer->user_id) {
                try {
                    app(NotificationService::class)->notifyUser(
                        $customer->user_id,
                        'refund_released',
                        '💵 Refund Released',
                        "Your refund of ₱" . number_format($refundAmount, 2)
                            . " for booking {$booking->booking_no} has been released.",
                        \App\Models\Notification::PRIORITY_HIGH,
                        ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                        "/customer/bookings/{$booking->booking_id}"
                    );
                } catch (\Throwable $notifyErr) {
                    Log::warning('Customer refund released notification failed: ' . $notifyErr->getMessage());
                }
            }

            return $this->ok($this->formatBooking($booking->fresh()), 'Refund released.');
        } catch (ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('Confirm refund error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to confirm refund: ' . $e->getMessage(), 500);
        }
    }

    public function adminDirectRefund(Request $request, Booking $booking): JsonResponse
    {
        try {
            $validated = $request->validate([
                'refund_amount'    => ['required', 'numeric', 'min:0'],
                'payment_method'   => ['nullable', 'string', 'max:50'],
                'reference_number' => ['nullable', 'string', 'max:100'],
                'reason'           => ['nullable', 'string', 'max:500'],
            ]);

            $refundAmount = (float) $validated['refund_amount'];
            $method = strtolower(str_replace(' ', '_', $validated['payment_method'] ?? 'cash'));
            $allowedMethods = ['cash', 'gcash', 'maya', 'bank_transfer', 'card', 'check'];
            if (!in_array($method, $allowedMethods, true)) {
                $method = 'cash';
            }

            $booking->loadMissing(['invoice', 'payments']);

            $payment = null;
            if ($refundAmount > 0) {
                $payment = DB::transaction(function () use ($booking, $refundAmount, $method, $validated) {
                    $payment = BookingPayment::create([
                        'booking_id'       => $booking->booking_id,
                        'payment_number'   => 'REF-' . now()->format('YmdHisv') . '-' . $booking->booking_id . '-' . random_int(100, 999),
                        'amount'           => $refundAmount,
                        'payment_method'   => $method,
                        'payment_type'     => 'refund',
                        'reference_number' => $validated['reference_number'] ?? null,
                        'notes'            => trim('Admin direct refund. ' . ($validated['reason'] ?? '')),
                        'status'           => 'completed',
                        'payment_date'     => now(),
                        'verified_by'      => auth()->id(),
                        'verified_at'      => now(),
                    ]);

                    $this->synchronizeBookingInvoice($booking);

                    return $payment;
                });
            }

            $refundState = $booking->refund_request_state;
            $refundState['status']           = 'released';
            $refundState['amount']           = $refundAmount;
            $refundState['released_amount']  = $refundAmount;
            $refundState['released_at']      = now()->toIso8601String();
            $refundState['released_by']      = optional($request->user())->user_id;
            $refundState['payment_id']       = $payment?->payment_id;
            $refundState['payment_method']   = $method;
            $refundState['reference_number'] = $validated['reference_number'] ?? null;
            $refundState['reason']           = $validated['reason'] ?? 'Cancelled by admin.';
            $refundState['admin_direct']     = true;

            Setting::setValue(
                self::SETTINGS_GROUP_REFUND_REQUESTS,
                'booking_' . $booking->booking_id,
                $refundState,
                'json'
            );

            $booking->update([
                'booking_status'      => 'cancelled',
                'cancellation_reason' => trim('Cancelled by admin. ' . ($validated['reason'] ?? '')),
            ]);
            $booking->serviceEvent?->update(['status' => 'cancelled']);

            $depositState = $booking->deposit_policy_state;
            $depositState['decision_status'] = 'cancelled';
            $depositState['decision_action'] = 'cancel';
            $depositState['decision_notes']  = $validated['reason'] ?? 'Cancelled by admin.';
            $depositState['decision_at']     = now()->toIso8601String();
            $depositState['decision_by']     = optional($request->user())->user_id;
            $depositState['extended_until']  = null;

            Setting::setValue(
                self::SETTINGS_GROUP_DEPOSIT_POLICY,
                'booking_' . $booking->booking_id,
                $depositState,
                'json'
            );

            $this->logCustom(
                'refund_admin_direct',
                'bookings',
                $booking->booking_id,
                "Booking {$booking->booking_no} cancelled by admin with refund ₱" . number_format($refundAmount, 2),
                [
                    'booking_no'       => $booking->booking_no,
                    'refund_amount'    => $refundAmount,
                    'payment_method'   => $method,
                    'reference_number' => $validated['reference_number'] ?? null,
                    'payment_id'       => $payment?->payment_id,
                    'reason'           => $validated['reason'] ?? null,
                ]
            );

            $customer = $booking->serviceEvent?->customer;
            if ($customer && $customer->user_id) {
                try {
                    $msg = $refundAmount > 0
                        ? "Your booking {$booking->booking_no} has been cancelled. Refund of ₱" . number_format($refundAmount, 2) . " released."
                        : "Your booking {$booking->booking_no} has been cancelled.";
                    app(NotificationService::class)->notifyUser(
                        $customer->user_id,
                        'refund_admin_direct',
                        'Booking Cancelled',
                        $msg,
                        \App\Models\Notification::PRIORITY_HIGH,
                        ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                        "/customer/bookings/{$booking->booking_id}"
                    );
                } catch (\Throwable $notifyErr) {
                    Log::warning('Admin direct refund notification failed: ' . $notifyErr->getMessage());
                }
            }

            return $this->ok($this->formatBooking($booking->fresh()), 'Booking cancelled with refund.');
        } catch (ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('Admin direct refund error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to cancel with refund: ' . $e->getMessage(), 500);
        }
    }

    public function rejectRefund(Request $request, Booking $booking): JsonResponse
    {
        try {
            $refundState = $booking->refund_request_state;

            if (($refundState['status'] ?? null) !== 'pending') {
                return $this->fail('No pending refund request for this booking.', 422);
            }

            $validated = $request->validate([
                'reason' => ['required', 'string', 'max:500'],
            ]);

            $refundState['status']         = 'rejected';
            $refundState['decided_at']     = now()->toIso8601String();
            $refundState['decided_by']     = optional($request->user())->user_id;
            $refundState['decision_notes'] = $validated['reason'];
            $refundState['reason']         = trim(($refundState['reason'] ?? '') . "\n[Rejected]: " . $validated['reason']);

            Setting::setValue(
                self::SETTINGS_GROUP_REFUND_REQUESTS,
                'booking_' . $booking->booking_id,
                $refundState,
                'json'
            );

            $this->logCustom(
                'refund_rejected',
                'bookings',
                $booking->booking_id,
                "Refund REJECTED for booking {$booking->booking_no}",
                [
                    'booking_no'  => $booking->booking_no,
                    'reason'      => $validated['reason'],
                    'rejected_at' => now()->toDateTimeString(),
                ]
            );

            $customer = $booking->serviceEvent?->customer;
            if ($customer && $customer->user_id) {
                try {
                    app(NotificationService::class)->notifyUser(
                        $customer->user_id,
                        'refund_rejected',
                        'Refund Request Update',
                        "Your refund request for booking {$booking->booking_no} has been rejected. Reason: {$validated['reason']}",
                        \App\Models\Notification::PRIORITY_MEDIUM,
                        ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                        "/customer/bookings/{$booking->booking_id}"
                    );
                } catch (\Throwable $notifyErr) {
                    Log::warning('Refund rejection notification failed: ' . $notifyErr->getMessage());
                }
            }

            return $this->ok($this->formatBooking($booking->fresh()), 'Refund rejected.');
        } catch (ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('Reject refund error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to reject refund: ' . $e->getMessage(), 500);
        }
    }

    public function depositDecision(Request $request, Booking $booking): JsonResponse
    {
        try {
            $validated = $request->validate([
                'action'          => ['required', 'in:cancel,extend,waive'],
                'extension_days'  => ['nullable', 'integer', 'min:1', 'max:90'],
                'notes'           => ['nullable', 'string', 'max:500'],
            ]);

            $action = $validated['action'];
            $notes  = $validated['notes'] ?? '';

            $booking->loadMissing('serviceEvent');
            $event    = $booking->serviceEvent;
            $eventDate = $event?->event_date;
            $policy = app(\App\Services\BookingPolicyService::class);
            $depositPaymentDays = max(0, (int) $policy->depositPaymentDays());
            $daysUntilEvent = $eventDate
                ? (int) now()->startOfDay()->diffInDays($eventDate->copy()->startOfDay(), false)
                : null;
            $isLateBooking = $daysUntilEvent !== null
                && $depositPaymentDays > 0
                && $daysUntilEvent >= 0
                && $daysUntilEvent < $depositPaymentDays;

            $state = [
                'decision_status'      => null,
                'decision_action'      => $action,
                'decision_notes'       => $notes,
                'decision_at'          => now()->toIso8601String(),
                'decision_by'          => optional($request->user())->user_id,
                'extended_until'       => null,
                'applied_at_approval'  => $isLateBooking,
            ];

            if ($action === 'cancel') {
                $booking->update([
                    'booking_status'      => 'cancelled',
                    'cancellation_reason' => trim('Deposit not paid within 1 week of event. ' . $notes),
                ]);
                $booking->serviceEvent?->update(['status' => 'cancelled']);
                $state['decision_status'] = 'cancelled';
            } elseif ($action === 'extend') {
                $days = (int) ($validated['extension_days'] ?? 7);
                $newDeadline = now()->addDays($days)->toDateString();
                $state['decision_status'] = 'extended';
                $state['extended_until']  = $newDeadline;
            } else {
                $state['decision_status'] = 'waived';
            }

            Setting::setValue(
                self::SETTINGS_GROUP_DEPOSIT_POLICY,
                'booking_' . $booking->booking_id,
                $state,
                'json'
            );

            $this->logCustom(
                'deposit_decision',
                'bookings',
                $booking->booking_id,
                "Deposit decision '{$action}' for booking {$booking->booking_no}",
                [
                    'booking_no'     => $booking->booking_no,
                    'action'         => $action,
                    'extension_days' => $validated['extension_days'] ?? null,
                    'extended_until' => $state['extended_until'],
                    'notes'          => $notes,
                    'decided_at'     => $state['decision_at'],
                ]
            );

            $customer = $booking->serviceEvent?->customer;
            if ($customer && $customer->user_id) {
                $messages = [
                    'cancel' => "Your booking {$booking->booking_no} has been cancelled due to unpaid deposit.",
                    'extend' => "Your deposit deadline for booking {$booking->booking_no} has been extended until {$state['extended_until']}.",
                    'waive'  => "Your deposit requirement for booking {$booking->booking_no} has been waived. Your booking remains confirmed.",
                ];
                try {
                    app(NotificationService::class)->notifyUser(
                        $customer->user_id,
                        'deposit_decision',
                        'Booking Update',
                        $messages[$action],
                        \App\Models\Notification::PRIORITY_HIGH,
                        ['booking_id' => $booking->booking_id, 'booking_no' => $booking->booking_no],
                        "/customer/bookings/{$booking->booking_id}"
                    );
                } catch (\Throwable $notifyErr) {
                    Log::warning('Deposit decision notification failed: ' . $notifyErr->getMessage());
                }
            }

            return $this->ok($this->formatBooking($booking->fresh()), 'Deposit decision recorded.');
        } catch (ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('Deposit decision error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->fail('Failed to record deposit decision: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // INGREDIENTS
    // ============================================================

    public function getBookingsWithIngredients(Request $request): JsonResponse
    {
        try {
            $bookings = Booking::with([
                'serviceEvent.customer.person',
                'serviceEvent.eventType',
                'items.menuItem.recipeIngredients.ingredient.stock',
            ])
                ->whereIn('booking_status', ['confirmed', 'pending_approval'])
                ->latest('booking_id')
                ->get();

            $result = [];

            foreach ($bookings as $booking) {
                $guestsCount    = (int) ($booking->serviceEvent?->guests_count ?? 0);
                $allIngredients = [];
                $menuItems      = [];

                foreach ($booking->items as $item) {
                    if (!$item->menu_item_id) continue;

                    $menuItem = $item->menuItem;
                    if (!$menuItem) continue;

                    $itemIngredients = [];

                    foreach ($menuItem->recipeIngredients as $recipe) {
                        $stock           = InventoryStock::where('ingredient_id', $recipe->ingredient_id)->first();
                        $quantityNeeded  = $recipe->quantity_per_pax * max(1, (int) $item->quantity);
                        $availableStock  = ($stock?->current_quantity ?? 0) - ($stock?->reserved_quantity ?? 0);
                        $shortage        = max(0, $quantityNeeded - $availableStock);

                        $purchased = PurchaseRequest::where('ingredient_id', $recipe->ingredient_id)
                            ->where('booking_id', $booking->booking_id)
                            ->whereIn('status', ['received', 'purchased'])
                            ->exists();

                        $ingredientData = [
                            'ingredient_id'    => $recipe->ingredient_id,
                            'name'             => $recipe->ingredient?->name ?? 'Unknown',
                            'unit'             => $recipe->unit ?? $recipe->ingredient?->unit ?? 'kg',
                            'per_pax'          => (float) $recipe->quantity_per_pax,
                            'quantity_needed'  => round($quantityNeeded, 2),
                            'current_stock'    => (float) ($stock?->current_quantity ?? 0),
                            'reserved_quantity' => (float) ($stock?->reserved_quantity ?? 0),
                            'available_stock'  => round($availableStock, 2),
                            'shortage'         => round($shortage, 2),
                            'purchased'        => $purchased,
                            'need_to_buy'      => !$purchased && $shortage > 0,
                            'unit_cost'        => (float) ($recipe->ingredient?->unit_cost ?? 0),
                        ];

                        $itemIngredients[] = $ingredientData;
                        $allIngredients[]  = $ingredientData;
                    }

                    $menuItems[] = [
                        'menu_item_id' => $menuItem->menu_item_id,
                        'name'         => $menuItem->name,
                        'quantity'     => $item->quantity,
                        'price'        => (float) $item->unit_price,
                        'ingredients'  => $itemIngredients,
                        'ingredients_summary' => [
                            'total'          => count($itemIngredients),
                            'need_to_buy'    => collect($itemIngredients)->where('need_to_buy', true)->count(),
                            'purchased'      => collect($itemIngredients)->where('purchased', true)->count(),
                            'total_shortage' => collect($itemIngredients)->sum('shortage'),
                        ],
                    ];
                }

                $uniqueIngredients = [];
                foreach ($allIngredients as $ing) {
                    $key = $ing['ingredient_id'];
                    if (!isset($uniqueIngredients[$key])) {
                        $uniqueIngredients[$key] = $ing;
                    } else {
                        $uniqueIngredients[$key]['quantity_needed'] += $ing['quantity_needed'];
                        $uniqueIngredients[$key]['shortage'] = max(
                            0,
                            $uniqueIngredients[$key]['quantity_needed'] - $uniqueIngredients[$key]['available_stock']
                        );
                        $uniqueIngredients[$key]['need_to_buy'] = $uniqueIngredients[$key]['shortage'] > 0;
                    }
                }
                $allIngredients = array_values($uniqueIngredients);

                $result[] = [
                    'booking_id'      => $booking->booking_id,
                    'booking_no'      => $booking->booking_no,
                    'booking_status'  => $booking->booking_status,
                    'customer_name'   => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                    'customer_email'  => $booking->serviceEvent?->customer?->person?->email,
                    'customer_phone'  => $booking->serviceEvent?->customer?->person?->phone,
                    'event_date'      => $booking->serviceEvent?->event_date?->toDateString(),
                    'event_time'      => $booking->serviceEvent?->event_time,
                    'venue'           => $booking->serviceEvent?->venue,
                    'guests_count'    => $guestsCount,
                    'menu_items'      => $menuItems,
                    'ingredients_summary' => [
                        'total_ingredients' => count($allIngredients),
                        'need_to_buy'       => collect($allIngredients)->where('need_to_buy', true)->count(),
                        'purchased'         => collect($allIngredients)->where('purchased', true)->count(),
                        'total_shortage'    => collect($allIngredients)->sum('shortage'),
                    ],
                    'all_ingredients' => $allIngredients,
                ];
            }

            if ($request->filled('search')) {
                $search = strtolower($request->input('search'));
                $result = array_filter($result, function ($booking) use ($search) {
                    return str_contains(strtolower($booking['booking_no']), $search)
                        || str_contains(strtolower($booking['customer_name']), $search);
                });
                $result = array_values($result);
            }

            $perPage = $request->integer('per_page', 10);
            if ($perPage < 1) $perPage = 10;
            if ($perPage > 1000) $perPage = 100;

            $page = $request->integer('page', 1);
            if ($page < 1) $page = 1;

            $total    = count($result);
            $lastPage = max(1, ceil($total / $perPage));
            if ($page > $lastPage) $page = $lastPage;

            $offset    = ($page - 1) * $perPage;
            $paginated = array_slice($result, $offset, $perPage);

            return response()->json([
                'success' => true,
                'data'    => [
                    'data'         => $paginated,
                    'total'        => $total,
                    'current_page' => $page,
                    'per_page'     => $perPage,
                    'last_page'    => $lastPage,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get bookings with ingredients error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to load ingredients data: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getBookingIngredientsDetails(Booking $booking): JsonResponse
    {
        try {
            $booking->load([
                'serviceEvent.customer.person',
                'serviceEvent.eventType',
                'items.menuItem.recipeIngredients.ingredient.stock',
            ]);

            $guestsCount    = (int) ($booking->serviceEvent?->guests_count ?? 0);
            $allIngredients = [];
            $menuItems      = [];

            foreach ($booking->items as $item) {
                if (!$item->menu_item_id) continue;

                $menuItem = $item->menuItem;
                if (!$menuItem) continue;

                $itemIngredients = [];

                foreach ($menuItem->recipeIngredients as $recipe) {
                    $stock           = InventoryStock::where('ingredient_id', $recipe->ingredient_id)->first();
                    $quantityNeeded  = $recipe->quantity_per_pax * max(1, (int) $item->quantity);
                    $availableStock  = ($stock?->current_quantity ?? 0) - ($stock?->reserved_quantity ?? 0);
                    $shortage        = max(0, $quantityNeeded - $availableStock);

                    $purchased = PurchaseRequest::where('ingredient_id', $recipe->ingredient_id)
                        ->where('booking_id', $booking->booking_id)
                        ->whereIn('status', ['received', 'purchased'])
                        ->exists();

                    $ingredientData = [
                        'ingredient_id'    => $recipe->ingredient_id,
                        'name'             => $recipe->ingredient?->name ?? 'Unknown',
                        'unit'             => $recipe->unit ?? $recipe->ingredient?->unit ?? 'kg',
                        'per_pax'          => (float) $recipe->quantity_per_pax,
                        'quantity_needed'  => round($quantityNeeded, 2),
                        'current_stock'    => (float) ($stock?->current_quantity ?? 0),
                        'reserved_quantity' => (float) ($stock?->reserved_quantity ?? 0),
                        'available_stock'  => round($availableStock, 2),
                        'shortage'         => round($shortage, 2),
                        'purchased'        => $purchased,
                        'need_to_buy'      => !$purchased && $shortage > 0,
                        'unit_cost'        => (float) ($recipe->ingredient?->unit_cost ?? 0),
                        'menu_items'       => [
                            [
                                'name'     => $menuItem->name,
                                'quantity' => $item->quantity,
                                'per_pax'  => $recipe->quantity_per_pax,
                                'required' => round($quantityNeeded, 2),
                            ],
                        ],
                    ];

                    $itemIngredients[] = $ingredientData;
                    $allIngredients[]  = $ingredientData;
                }

                $menuItems[] = [
                    'menu_item_id' => $menuItem->menu_item_id,
                    'name'         => $menuItem->name,
                    'quantity'     => $item->quantity,
                    'price'        => (float) $item->unit_price,
                    'ingredients'  => $itemIngredients,
                    'ingredients_summary' => [
                        'total'          => count($itemIngredients),
                        'need_to_buy'    => collect($itemIngredients)->where('need_to_buy', true)->count(),
                        'purchased'      => collect($itemIngredients)->where('purchased', true)->count(),
                        'total_shortage' => collect($itemIngredients)->sum('shortage'),
                    ],
                ];
            }

            $uniqueIngredients = [];
            foreach ($allIngredients as $ing) {
                $key = $ing['ingredient_id'];
                if (!isset($uniqueIngredients[$key])) {
                    $uniqueIngredients[$key] = $ing;
                } else {
                    $uniqueIngredients[$key]['quantity_needed'] += $ing['quantity_needed'];
                    $uniqueIngredients[$key]['shortage'] = max(
                        0,
                        $uniqueIngredients[$key]['quantity_needed'] - $uniqueIngredients[$key]['available_stock']
                    );
                    $uniqueIngredients[$key]['need_to_buy'] = $uniqueIngredients[$key]['shortage'] > 0;
                    $uniqueIngredients[$key]['menu_items'] = array_merge(
                        $uniqueIngredients[$key]['menu_items'] ?? [],
                        $ing['menu_items'] ?? []
                    );
                }
            }
            $allIngredients = array_values($uniqueIngredients);

            return response()->json([
                'success' => true,
                'data'    => [
                    'booking_id'     => $booking->booking_id,
                    'booking_no'     => $booking->booking_no,
                    'booking_status' => $booking->booking_status,
                    'customer_name'  => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                    'customer_email' => $booking->serviceEvent?->customer?->person?->email,
                    'customer_phone' => $booking->serviceEvent?->customer?->person?->phone,
                    'event_date'     => $booking->serviceEvent?->event_date?->toDateString(),
                    'event_time'     => $booking->serviceEvent?->event_time,
                    'venue'          => $booking->serviceEvent?->venue,
                    'guests_count'   => $guestsCount,
                    'menu_items'     => $menuItems,
                    'ingredients_summary' => [
                        'total_ingredients' => count($allIngredients),
                        'need_to_buy'       => collect($allIngredients)->where('need_to_buy', true)->count(),
                        'purchased'         => collect($allIngredients)->where('purchased', true)->count(),
                        'total_shortage'    => collect($allIngredients)->sum('shortage'),
                    ],
                    'all_ingredients' => $allIngredients,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get booking ingredients details error: ' . $e->getMessage(), [
                'booking_id' => $booking->booking_id,
                'trace'      => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to load ingredients details: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getMenuItemIngredients(Booking $booking, $menuItemId): JsonResponse
    {
        try {
            $menuItem = MenuItem::find($menuItemId);
            if (!$menuItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Menu item not found',
                ], 404);
            }

            $booking->loadMissing(['serviceEvent', 'items']);
            $guestsCount     = (int) ($booking->serviceEvent?->guests_count ?? 0);
            $servingQuantity = (int) $booking->items
                ->where('menu_item_id', (int) $menuItemId)
                ->sum('quantity');
            if ($servingQuantity <= 0) {
                $servingQuantity = max(1, $guestsCount);
            }
            $ingredients = [];

            foreach ($menuItem->recipeIngredients as $recipe) {
                $stock           = InventoryStock::where('ingredient_id', $recipe->ingredient_id)->first();
                $quantityNeeded  = $recipe->quantity_per_pax * $servingQuantity;
                $availableStock  = ($stock?->current_quantity ?? 0) - ($stock?->reserved_quantity ?? 0);
                $shortage        = max(0, $quantityNeeded - $availableStock);

                $purchased = PurchaseRequest::where('ingredient_id', $recipe->ingredient_id)
                    ->where('booking_id', $booking->booking_id)
                    ->whereIn('status', ['received', 'purchased'])
                    ->exists();

                $ingredients[] = [
                    'ingredient_id'    => $recipe->ingredient_id,
                    'name'             => $recipe->ingredient?->name ?? 'Unknown',
                    'unit'             => $recipe->unit ?? $recipe->ingredient?->unit ?? 'kg',
                    'per_pax'          => (float) $recipe->quantity_per_pax,
                    'quantity_needed'  => round($quantityNeeded, 2),
                    'current_stock'    => (float) ($stock?->current_quantity ?? 0),
                    'reserved_quantity' => (float) ($stock?->reserved_quantity ?? 0),
                    'available_stock'  => round($availableStock, 2),
                    'shortage'         => round($shortage, 2),
                    'purchased'        => $purchased,
                    'need_to_buy'      => !$purchased && $shortage > 0,
                    'unit_cost'        => (float) ($recipe->ingredient?->unit_cost ?? 0),
                ];
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'menu_item_id'   => $menuItem->menu_item_id,
                    'menu_item_name' => $menuItem->name,
                    'guests_count'   => $guestsCount,
                    'ingredients'    => $ingredients,
                    'summary'        => [
                        'total_ingredients' => count($ingredients),
                        'need_to_buy'       => collect($ingredients)->where('need_to_buy', true)->count(),
                        'purchased'         => collect($ingredients)->where('purchased', true)->count(),
                        'total_shortage'    => collect($ingredients)->sum('shortage'),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get menu item ingredients error: ' . $e->getMessage(), [
                'booking_id'   => $booking->booking_id,
                'menu_item_id' => $menuItemId,
                'trace'        => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to load menu item ingredients: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function markIngredientsPurchasedPerBooking(Request $request, Booking $booking): JsonResponse
    {
        try {
            $data = $request->validate([
                'ingredient_ids'   => 'required|array',
                'ingredient_ids.*' => 'exists:ingredients,ingredient_id',
                'menu_item_id'     => 'nullable|exists:menu_items,menu_item_id',
            ]);

            $updated         = 0;
            $ingredientNames = [];
            $menuItemName    = null;

            if ($data['menu_item_id'] ?? false) {
                $menuItem     = MenuItem::find($data['menu_item_id']);
                $menuItemName = $menuItem?->name;
            }

            foreach ($data['ingredient_ids'] as $ingredientId) {
                $existingRequest = PurchaseRequest::where('ingredient_id', $ingredientId)
                    ->where('booking_id', $booking->booking_id)
                    ->where('status', 'pending')
                    ->first();

                if ($existingRequest) {
                    $existingRequest->update(['status' => 'received']);
                    $updated++;
                } else {
                    $setting = Setting::where('group', 'ingredients_summary')
                        ->where('key', 'booking_' . $booking->booking_id)
                        ->first();

                    $ingredients = $setting ? $this->decodeSettingValue($setting->value) : [];
                    $found       = collect($ingredients)->firstWhere('ingredient_id', $ingredientId);

                    if ($found && ($found['shortage'] ?? 0) > 0) {
                        PurchaseRequest::create([
                            'pr_number'     => 'PRQ-' . now()->format('YmdHis') . '-' . random_int(100, 999),
                            'ingredient_id' => $ingredientId,
                            'quantity'      => $found['shortage'],
                            'urgency'       => 'normal',
                            'status'        => 'received',
                            'notes'         => "Marked as purchased for booking {$booking->booking_no}" .
                                ($menuItemName ? " (Menu: {$menuItemName})" : ''),
                            'requested_by'  => auth()->id() ?? 1,
                            'booking_id'    => $booking->booking_id,
                        ]);
                        $updated++;
                    }
                }

                $ingredient = Ingredient::find($ingredientId);
                if ($ingredient) {
                    $ingredientNames[] = $ingredient->name;
                }
            }

            $setting = Setting::where('group', 'ingredients_summary')
                ->where('key', 'booking_' . $booking->booking_id)
                ->first();

            if ($setting) {
                $ingredients = $this->decodeSettingValue($setting->value);
                foreach ($ingredients as &$ing) {
                    if (in_array($ing['ingredient_id'], $data['ingredient_ids'])) {
                        $ing['purchased'] = true;
                    }
                }
                $setting->update(['value' => json_encode($ingredients)]);
            }

            $message = $menuItemName
                ? "{$updated} ingredients marked as purchased for {$menuItemName}"
                : "{$updated} ingredients marked as purchased";

            return response()->json([
                'success' => true,
                'data'    => [
                    'updated'        => $updated,
                    'ingredients'    => $ingredientNames,
                    'menu_item_name' => $menuItemName,
                    'total_selected' => count($data['ingredient_ids']),
                ],
                'message' => $message,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Mark ingredients purchased error: ' . $e->getMessage(), [
                'booking_id' => $booking->booking_id,
                'trace'      => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark ingredients: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function markAllIngredientsPurchased(Booking $booking): JsonResponse
    {
        try {
            $setting = Setting::where('group', 'ingredients_summary')
                ->where('key', 'booking_' . $booking->booking_id)
                ->first();

            $ingredients = $setting ? $this->decodeSettingValue($setting->value) : [];
            $needToBuy   = array_filter($ingredients, function ($ing) {
                return ($ing['need_to_buy'] ?? false) === true;
            });

            if (empty($needToBuy)) {
                return response()->json([
                    'success' => true,
                    'message' => 'All ingredients are already purchased or sufficiently stocked.',
                ]);
            }

            $ingredientIds = array_column($needToBuy, 'ingredient_id');

            $request = new Request([
                'ingredient_ids' => $ingredientIds,
            ]);

            return $this->markIngredientsPurchasedPerBooking($request, $booking);
        } catch (\Exception $e) {
            Log::error('Mark all ingredients purchased error: ' . $e->getMessage(), [
                'booking_id' => $booking->booking_id,
                'trace'      => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark all ingredients: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Admin cancels / withdraws a pending reschedule proposal
     * that has NOT yet been accepted by the customer.
     */
    public function cancelRescheduleProposal(Request $request, Booking $booking): JsonResponse
    {
        try {
            $validated = $request->validate([
                'reason' => ['nullable', 'string', 'max:500'],
            ]);

            if ($booking->reschedule_status !== 'pending') {
                return $this->fail(
                    'No pending reschedule request to cancel for this booking.',
                    422
                );
            }

            $booking->loadMissing('serviceEvent');

            $oldData    = $booking->toArray();
            $proposedBy = $booking->reschedule_proposed_by;

            DB::transaction(function () use ($booking) {
                $restoreStatus = in_array($booking->booking_status, ['confirmed', 'ongoing'], true)
                    ? $booking->booking_status
                    : 'confirmed';

                $booking->update([
                    'booking_status'         => $restoreStatus,
                    'reschedule_status'      => 'cancelled',
                    'reschedule_proposed_by' => null,
                    'reschedule_source'      => null,
                    'requested_date'         => null,
                    'requested_time'         => null,
                    'reschedule_reason'      => null,
                    'reschedule_proposed_at' => null,
                    'original_event_date'    => null,
                    'original_event_time'    => null,
                ]);
            });

            $this->logCustom(
                'reschedule_proposal_cancelled_by_admin',
                'bookings',
                $booking->booking_id,
                "Admin cancelled reschedule proposal for {$booking->booking_no}",
                [
                    'booking_no'   => $booking->booking_no,
                    'customer'     => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                    'old_status'   => $oldData['booking_status'] ?? 'unknown',
                    'new_status'   => 'confirmed',
                    'proposed_by'  => $proposedBy,
                    'cancelled_at' => now()->toDateTimeString(),
                    'reason'       => $validated['reason'] ?? null,
                ]
            );

            try {
                $customer = $booking->serviceEvent?->customer;
                if ($customer && $customer->user_id) {
                    app(NotificationService::class)->notifyUser(
                        $customer->user_id,
                        'reschedule_proposal_cancelled',
                        'Reschedule Proposal Withdrawn',
                        "The proposed new schedule for booking {$booking->booking_no} has been withdrawn by our team.\n\n" .
                            "Your original event schedule remains confirmed.\n\n" .
                            "If you have questions, please contact us.",
                        \App\Models\Notification::PRIORITY_MEDIUM,
                        [
                            'booking_id' => $booking->booking_id,
                            'booking_no' => $booking->booking_no,
                        ],
                        "/customer/bookings/{$booking->booking_id}"
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Reschedule cancel notification failed: ' . $e->getMessage());
            }

            return $this->ok(
                $this->formatBooking($booking->fresh()),
                'Reschedule proposal cancelled. Booking restored to confirmed.'
            );
        } catch (ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('Cancel reschedule proposal error: ' . $e->getMessage(), [
                'booking_id' => $booking->booking_id,
                'trace'      => $e->getTraceAsString(),
            ]);
            return $this->fail('Failed to cancel reschedule proposal: ' . $e->getMessage(), 500);
        }
    }

    public function getIngredientsSummary(Booking $booking): JsonResponse
    {
        return $this->getBookingIngredientsDetails($booking);
    }

    public function markIngredientsPurchased(Request $request, Booking $booking): JsonResponse
    {
        return $this->markIngredientsPurchasedPerBooking($request, $booking);
    }

    public function getCompleted(Request $request): JsonResponse
    {
        $request->merge(['status' => 'completed']);
        return $this->index($request);
    }

    // ============================================================
    // PRIVATE HELPERS
    // ============================================================

    private function validateBookingCompletion(Booking $booking): array
    {
        $booking->loadMissing(['payments', 'invoice', 'quotation', 'equipment.equipment', 'order', 'serviceEvent']);
        $missing = [];

        $payments  = $this->loadedRelationCollection($booking, 'payments');
        $equipment = $this->loadedRelationCollection($booking, 'equipment');

        $totalAmount = (float) ($booking->invoice?->total_amount ?? $booking->quotation?->total_amount ?? 0);
        $additionalCharges = 0.0;
        if ($booking->invoice) {
            $additionalCharges += (float) ($booking->invoice->additional_charges ?? 0);
        }
        if ($booking->relationLoaded('charges')) {
            $additionalCharges += (float) $booking->charges->where('charge_kind', 'charge')->sum('amount');
        } elseif (Schema::hasTable('booking_charges')) {
            $additionalCharges += (float) DB::table('booking_charges')
                ->where('booking_id', $booking->booking_id)
                ->where('charge_kind', 'charge')
                ->sum('amount');
        }

        $damageAmount = (float) $equipment->sum(fn($item) => (float) ($item->damage_charge ?? 0) + (float) ($item->missing_charge ?? 0));
        $paidAmount   = (float) $payments->where('status', 'completed')->sum('amount');
        $requiredPayment = $totalAmount;

        if ($totalAmount > 0 && $paidAmount + 0.01 < $requiredPayment) {
            $missing[] = 'Please pay the remaining balance before completing this booking.';
        }

        $notReturned = $equipment->filter(fn($equipmentRow) => ! in_array($equipmentRow->status, ['returned'], true));
        if ($notReturned->count() > 0) {
            $missing[] = 'Equipment return is not yet complete.';
        }

        $hasDamageOrMissing = $equipment->filter(fn($row) => ((int) ($row->quantity_damaged ?? 0) > 0) || ((int) ($row->quantity_missing ?? 0) > 0))->count() > 0;
        if ($hasDamageOrMissing && $damageAmount > 0 && $paidAmount + 0.01 < ($totalAmount + $damageAmount + $additionalCharges)) {
            $missing[] = 'Damage or missing equipment charges must be paid.';
        }

        $ingredients = $this->bookingIngredients($booking);
        $unpurchased = collect($ingredients)->filter(fn($ingredient) => ($ingredient['need_to_buy'] ?? false) && !($ingredient['purchased'] ?? false));
        if ($unpurchased->count() > 0) {
            $missing[] = 'Please complete ingredients purchase first.';
        }

        $order = $booking->order;
        if ($order) {
            $metadata      = $this->orderMetadata($order);
            $kitchenTasks  = $metadata['kitchen_preparation'] ?? $this->settingArray('kitchen_tasks', 'order_' . $order->order_id);
            $kitchenPending = collect($kitchenTasks)->filter(function ($task) {
                if (($task['is_header'] ?? false) === true) return false;
                return ! (($task['is_done'] ?? false) || (($task['status'] ?? 'pending') === 'completed'));
            });
            if ($kitchenPending->count() > 0) {
                $missing[] = 'Kitchen preparation checklist is not yet done.';
            }

            $deliveryItems     = $metadata['delivery_preparation'] ?? $this->settingArray('delivery_items', 'order_' . $order->order_id);
            $needsDeliveryPrep = in_array(strtolower((string) $booking->serviceEvent?->delivery_method), ['delivery', 'buffet', 'setup'], true)
                || in_array(strtolower((string) $booking->serviceEvent?->service_type), ['buffet', 'tray'], true);
            if ($needsDeliveryPrep && count($deliveryItems) === 0) {
                $missing[] = 'Delivery preparation items are not added yet.';
            }
            $deliveryPending = collect($deliveryItems)->filter(function ($item) {
                return ! (($item['is_ready'] ?? false) || in_array(($item['status'] ?? 'pending'), ['ready', 'delivered', 'completed'], true));
            });
            if ($deliveryPending->count() > 0) {
                $missing[] = 'Delivery preparation checklist is not yet complete.';
            }
        } else {
            $missing[] = 'Order record is missing.';
        }

        return [
            'can_complete' => empty($missing),
            'missing'      => array_values(array_unique($missing)),
        ];
    }

    private function bookingIngredients(Booking $booking): array
    {
        $ingredients = $this->settingArray('ingredients_summary', 'booking_' . $booking->booking_id);
        if (empty($ingredients)) {
            $ingredients = $this->computeIngredientsFromBooking($booking);
            if (!empty($ingredients)) {
                Setting::setValue('ingredients_summary', 'booking_' . $booking->booking_id, $ingredients, 'json');
            }
        }
        return $ingredients;
    }

    private function orderMetadata($order): array
    {
        if (!$order) return [];
        return $this->settingArray('order_metadata', 'order_' . $order->order_id);
    }

    private function settingArray(string $group, string $key): array
    {
        $setting = Setting::where('group', $group)->where('key', $key)->first();
        if (!$setting) return [];
        return $this->decodeSettingValue($setting->value);
    }

    private function decodeSettingValue($value): array
    {
        if (is_array($value)) return $value;
        if (is_object($value)) return (array) $value;
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function computeIngredientsFromBooking(Booking $booking): array
    {
        $ingredients = [];
        $booking->loadMissing(['items.menuItem.recipeIngredients.ingredient']);

        foreach ($booking->items as $item) {
            if (!$item->menu_item_id) continue;

            $menuItem = $item->menuItem;
            if (!$menuItem) continue;

            foreach ($menuItem->recipeIngredients as $recipe) {
                $ingredientId    = $recipe->ingredient_id;
                $quantityPerPax  = (float) $recipe->quantity_per_pax;
                $requiredQty     = $quantityPerPax * $item->quantity;

                if (!isset($ingredients[$ingredientId])) {
                    $stock      = InventoryStock::where('ingredient_id', $ingredientId)->first();
                    $ingredient = Ingredient::find($ingredientId);

                    $ingredients[$ingredientId] = [
                        'ingredient_id'     => $ingredientId,
                        'name'              => $ingredient?->name ?? 'Unknown',
                        'unit'              => $recipe->unit ?? $ingredient?->unit ?? 'kg',
                        'per_pax'           => $quantityPerPax,
                        'quantity_needed'   => 0,
                        'current_stock'     => $stock?->current_quantity ?? 0,
                        'reserved_quantity' => $stock?->reserved_quantity ?? 0,
                        'available_stock'   => ($stock?->current_quantity ?? 0) - ($stock?->reserved_quantity ?? 0),
                        'unit_cost'         => $ingredient?->unit_cost ?? 0,
                        'menu_items'        => [],
                        'purchased'         => false,
                    ];
                }

                $ingredients[$ingredientId]['quantity_needed'] += $requiredQty;
                $ingredients[$ingredientId]['menu_items'][] = [
                    'name'     => $menuItem->name,
                    'quantity' => $item->quantity,
                    'per_pax'  => $quantityPerPax,
                    'required' => $requiredQty,
                ];
            }
        }

        foreach ($ingredients as &$ing) {
            $ing['shortage']    = max(0, $ing['quantity_needed'] - $ing['available_stock']);
            $ing['need_to_buy'] = $ing['shortage'] > 0;
            $ing['status']      = $ing['shortage'] > 0 ? 'insufficient' : ($ing['available_stock'] < $ing['quantity_needed'] * 1.2 ? 'low' : 'sufficient');
        }

        return array_values($ingredients);
    }

    private function formatBooking(Booking $booking): array
    {
        $booking->loadMissing($this->bookingRelations());

        $policy = app(\App\Services\BookingPolicyService::class);
        $depositPaymentDays     = max(0, (int) $policy->depositPaymentDays());
        $cancellationCutoffDays = max(0, (int) $policy->cancellationCutoffDays());

        $event  = $booking->serviceEvent;
        $person = $event?->customer?->person;

        $isWithinCancellationCutoff = false;
        $daysUntilEvent             = null;
        if ($event?->event_date) {
            $daysUntilEvent = (int) now()->startOfDay()->diffInDays(
                $event->event_date->copy()->startOfDay(),
                false
            );
            if ($cancellationCutoffDays > 0) {
                $isWithinCancellationCutoff = $daysUntilEvent >= 0 && $daysUntilEvent < $cancellationCutoffDays;
            }
        }

        $isLateBooking = $daysUntilEvent !== null
            && $depositPaymentDays > 0
            && $daysUntilEvent >= 0
            && $daysUntilEvent < $depositPaymentDays;

        $startDate = $event?->event_date?->toDateString();
        $endDate   = $event?->event_end_date?->toDateString() ?: $startDate;
        $days      = 1;
        if ($startDate && $endDate) {
            $days = max(1, Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1);
        }
        $eventDayRowsForMax = $this->loadedRelationCollection($booking, 'eventDays');
        $mealMaxDay         = (int) ($eventDayRowsForMax->max('day_number') ?? 1);
        $days               = max($days, $mealMaxDay);
        $isMultiDay         = ($event?->booking_scope === 'multi_day') || $days > 1;
        $totalAmount   = (float) ($booking->invoice?->total_amount ?? $booking->quotation?->total_amount ?? 0);
        $payments      = $this->loadedRelationCollection($booking, 'payments');
        $bookingItems  = $this->loadedRelationCollection($booking, 'items');
        $eventDayRows  = $this->loadedRelationCollection($booking, 'eventDays');
        $mealServiceRows = $this->loadedRelationCollection($booking, 'mealServices');
        $chargeRows    = $this->loadedRelationCollection($booking, 'charges');
        $equipmentRows = $this->loadedRelationCollection($booking, 'equipment');
        $trackingRows  = $this->loadedRelationCollection($booking, 'tracking');

        // ⭐ Compute NET paid amount (completed non-refund payments MINUS
        //    completed refunds). This makes the yellow deposit highlight
        //    clear as soon as ANY completed payment exists, and the
        //    balance always reflects reality.
        $grossPaid = (float) $payments
            ->where('status', 'completed')
            ->where('payment_type', '!=', 'refund')
            ->sum('amount');

        $refunded = (float) $payments
            ->where('status', 'completed')
            ->where('payment_type', 'refund')
            ->sum('amount');

        $paidAmount = max(0, $grossPaid - $refunded);

        $preparationTracking = $trackingRows->where('stage', 'preparation')->first();
        $preparationMetadata = json_decode((string) ($preparationTracking?->notes ?? '[]'), true);
        $preparationMetadata = is_array($preparationMetadata) ? $preparationMetadata : [];
        $assignedStaff       = collect($preparationMetadata['assigned_staff'] ?? [])->values();

        $completedTracking   = $trackingRows->where('stage', 'completed')->first();
        $completionMetadata  = json_decode((string) ($completedTracking?->notes ?? '[]'), true);
        $completionMetadata  = is_array($completionMetadata) ? $completionMetadata : [];

        $ongoingTracking     = $trackingRows->where('stage', 'ongoing')->first();
        $ongoingMetadata     = json_decode((string) ($ongoingTracking?->notes ?? '[]'), true);
        $ongoingMetadata     = is_array($ongoingMetadata) ? $ongoingMetadata : [];

        $mealServices = $mealServiceRows->map(fn($meal) => [
            'meal_service_id'     => $meal->meal_service_id,
            'id'                  => $meal->meal_service_id,
            'event_day_id'        => $meal->event_day_id,
            'day_number'          => (int) $meal->day_number,
            'service_date'        => $meal->service_date?->toDateString(),
            'date'                => $meal->service_date?->toDateString(),
            'meal_type'           => $meal->meal_type,
            'serving_time'        => $meal->serving_time,
            'preparation_time'    => $meal->preparation_time,
            'dispatch_time'       => $meal->dispatch_time,
            'arrival_time'        => $meal->arrival_time,
            'pax'                 => (int) $meal->pax,
            'menu_source'         => $meal->menu_source,
            'menu_item_id'        => $meal->menu_item_id,
            'package_id'          => $meal->package_id,
            'menu_name'           => $meal->menu_name,
            'menu_description'    => $meal->menu_description,
            'price_per_head'      => (float) $meal->price_per_head,
            'total_meal_amount'   => (float) $meal->total_meal_amount,
            'filters'             => ($meal->relationLoaded('filters') ? $meal->filters : collect())->map(fn($filter) => [
                'filter_key'   => $filter->filter_key,
                'filter_value' => $filter->filter_value,
            ])->values(),
            'custom_items'        => ($meal->relationLoaded('customItems') ? $meal->customItems : collect())->map(fn($item) => [
                'meal_service_custom_item_id' => $item->meal_service_custom_item_id,
                'menu_item_id'                => $item->menu_item_id,
                'item_name'                   => $item->item_name ?? $item->menuItem?->name,
                'description'                 => $item->description,
                'quantity'                    => (int) $item->quantity,
                'unit_price'                  => (float) $item->unit_price,
                'notes'                       => $item->notes,
            ])->values(),
            'notes'               => $meal->notes,
            'preparation_status'  => $meal->preparation_status,
            'delivery_status'     => $meal->delivery_status,
            'serving_status'      => $meal->serving_status,
            'meal_status'         => $meal->meal_status,
        ])->values();

        $eventDays = $eventDayRows->map(fn($day) => [
            'event_day_id'    => $day->event_day_id,
            'day_number'      => (int) $day->day_number,
            'date'            => $day->date?->toDateString(),
            'day_status'      => $day->day_status,
            'day_total_amount' => (float) $day->day_total_amount,
        ])->values();

        $depositPolicyState  = $booking->deposit_policy_state;
        $refundRequestState  = $booking->refund_request_state;

        // ⭐ REQUEST #11 & #12 & #13: 3-day warning for NON-approved bookings.
        // Only fires when:
        //   - Status is NOT approved/confirmed
        //   - Event date has NOT passed
        //   - Event is within 3 days (inclusive of the event date)
        $bookingStatusLower = strtolower((string) $booking->booking_status);
        $isApprovedStatus   = in_array($bookingStatusLower, ['confirmed', 'approved'], true);

        $requiresThreeDayWarning = false;
        $threeDayWarningMessage  = null;

        if (
            ! $isApprovedStatus &&
            $daysUntilEvent !== null &&
            $daysUntilEvent >= 0 &&
            $daysUntilEvent <= 3 &&
            ! in_array($bookingStatusLower, ['cancelled', 'rejected', 'completed'], true)
        ) {
            $requiresThreeDayWarning = true;
            $threeDayWarningMessage = match (true) {
                $daysUntilEvent === 0 => 'Event is TODAY and booking is still pending approval.',
                $daysUntilEvent === 1 => 'Event is TOMORROW and booking is still pending approval.',
                $daysUntilEvent === 2 => 'Event is in 2 days and booking is still pending approval.',
                $daysUntilEvent === 3 => 'Event is in 3 days and booking is still pending approval.',
                default               => "Event is in {$daysUntilEvent} days and booking is still pending approval.",
            };
        }

        // ⭐ REQUEST #10: Red highlight when Approved + deposit deadline passed + unpaid.
        //    ⭐ UPDATED: Uses NET paid amount so ANY completed payment
        //    (deposit OR partial) clears the red/yellow highlight.
        $depositDeadlinePassed = false;
        if ($isApprovedStatus) {
            $decisionStatus = strtolower((string) ($depositPolicyState['decision_status'] ?? ''));
            $isDepositWaivedOrCancelled = in_array($decisionStatus, ['waived', 'cancelled'], true);

            $depositDueDateValue = $booking->deposit_due_date
                ?? ($startDate
                    ? Carbon::parse($startDate)
                    ->subDays($depositPaymentDays)
                    ->toDateString()
                    : null);

            // ⭐ If ANY payment (deposit or partial) is on file, the
            //    deadline is considered met — no red/yellow highlight.
            $hasAnyPayment = $paidAmount > 0;

            if (
                ! $isDepositWaivedOrCancelled &&
                ! $hasAnyPayment &&
                ! $isLateBooking &&
                $depositDueDateValue !== null
            ) {
                try {
                    $dueCarbon = $depositDueDateValue instanceof Carbon
                        ? $depositDueDateValue
                        : Carbon::parse($depositDueDateValue);

                    $depositDeadlinePassed = now()->startOfDay()->greaterThanOrEqualTo(
                        $dueCarbon->copy()->startOfDay()
                    );
                } catch (\Throwable $e) {
                    $depositDeadlinePassed = false;
                }
            }
        }

        // ⭐ deposit_paid now reflects ANY completed payment (not just
        //    deposit-typed), so the frontend can reliably clear the
        //    yellow highlight the moment a deposit or partial is paid.
        $depositPaid = $paidAmount;
        if ($depositPaid <= 0) {
            $depositPaid = (float) ($refundRequestState['deposit_snapshot'] ?? 0);
        }
        if ($depositPaid <= 0) {
            $depositPaid = (float) ($booking->invoice?->down_payment ?? 0);
        }
        return [
            'id'             => $booking->booking_id,
            'booking_id'     => $booking->booking_id,
            'booking_no'     => $booking->booking_no,
            'booking_status' => $booking->booking_status,

            'customer_name'    => trim(($person?->first_name ?? '') . ' ' . ($person?->last_name ?? '')),
            'customer_email'   => $person?->email,
            'customer_phone'   => $person?->phone,
            'customer_address' => $person?->address_line_1,
            'address_line_1'   => $person?->address_line_1,
            'city'             => $person?->city,
            'province'         => $person?->province,
            'postal_code'      => $person?->postal_code,
            'country'          => $person?->country,

            // Reschedule metadata
            'requested_date'         => $booking->requested_date?->toDateString(),
            'requested_time'         => $booking->requested_time,
            'reschedule_reason'      => $booking->reschedule_reason,
            'reschedule_proposed_by' => $booking->reschedule_proposed_by,
            'reschedule_status'      => $booking->reschedule_status,
            'reschedule_source'      => $booking->reschedule_source,
            'reschedule_proposed_at' => optional($booking->reschedule_proposed_at)->toIso8601String(),
            'original_event_date'    => optional($booking->original_event_date)->toDateString(),
            'original_event_time'    => $booking->original_event_time,

            // Reschedule deadline metadata
            'customer_reschedule_deadline' => (function () use ($booking) {
                if (
                    $booking->reschedule_status === 'pending' &&
                    $booking->reschedule_proposed_by === 'admin' &&
                    $booking->reschedule_proposed_at
                ) {
                    $hours = app(\App\Services\BookingPolicyService::class)->customerRescheduleResponseHours();
                    return $booking->reschedule_proposed_at->copy()->addHours($hours)->toIso8601String();
                }
                return null;
            })(),
            'admin_reschedule_deadline' => (function () use ($booking) {
                if (
                    $booking->reschedule_status === 'pending' &&
                    $booking->reschedule_proposed_by === 'customer' &&
                    $booking->reschedule_proposed_at
                ) {
                    $hours = app(\App\Services\BookingPolicyService::class)->adminRescheduleResponseHours();
                    return $booking->reschedule_proposed_at->copy()->addHours($hours)->toIso8601String();
                }
                return null;
            })(),
            'customer_reschedule_response_hours' => app(\App\Services\BookingPolicyService::class)->customerRescheduleResponseHours(),
            'admin_reschedule_response_hours'    => app(\App\Services\BookingPolicyService::class)->adminRescheduleResponseHours(),

            'event_type_id'       => $event?->event_type_id,
            'event_type_name'     => $event?->eventType?->name,
            'event_date'          => $startDate,
            'event_end_date'      => $endDate,
            'days'                => $days,
            'is_multi_day'        => $isMultiDay,
            'booking_scope'       => $event?->booking_scope ?? ($isMultiDay ? 'multi_day' : 'regular'),
            'event_time'          => $event?->event_time,
            'venue'               => $event?->venue,
            'location'            => $event?->venue,
            'guests_count'        => (int) ($event?->guests_count ?? 0),
            'service_type'        => $event?->service_type,
            'delivery_method'     => $event?->delivery_method,
            'menu_selection_type' => $event?->menu_selection_type,
            'special_requests'    => $event?->special_requests,

            'total_amount'   => $totalAmount,
            'paid_amount'    => $paidAmount,
            'balance'        => max(0, $totalAmount - $paidAmount),
            'payment_status' => $paidAmount <= 0 ? 'pending' : ($paidAmount < $totalAmount ? 'partial' : 'paid'),

            'deposit_decision_status' => $depositPolicyState['decision_status'] ?? null,
            'deposit_decision_action' => $depositPolicyState['decision_action'] ?? null,
            'deposit_decision_notes'  => $depositPolicyState['decision_notes'] ?? null,
            'deposit_extended_until'  => $depositPolicyState['extended_until'] ?? null,
            'deposit_decision_at'     => $depositPolicyState['decision_at'] ?? null,

            'refund_status'         => $refundRequestState['status'] ?? null,
            'refund_amount'         => (float) ($refundRequestState['amount'] ?? 0),
            'refund_reason'         => $refundRequestState['reason'] ?? null,
            'refund_released_amount' => (float) ($refundRequestState['released_amount'] ?? 0),
            'refund_released_at'    => $refundRequestState['released_at'] ?? null,
            'refund_payment_id'     => $refundRequestState['payment_id'] ?? null,
            'refund_admin_direct'   => (bool) ($refundRequestState['admin_direct'] ?? false),
            'refund_requested_at'   => $refundRequestState['requested_at'] ?? null,
            'refund_requested_by'   => $refundRequestState['requested_by'] ?? null,

            'deposit_paid' => $depositPaid,
            'deposit_due_date' => (function () use ($startDate, $depositPaymentDays, $isLateBooking) {
                if (!$startDate) return null;
                if ($isLateBooking) return null;
                if ($depositPaymentDays > 0) {
                    $computed = Carbon::parse($startDate)->subDays($depositPaymentDays);
                    return $computed->isBefore(now()->startOfDay())
                        ? now()->startOfDay()->toDateString()
                        : $computed->toDateString();
                }
                return Carbon::parse($startDate)->toDateString();
            })(),
            'is_late_booking'    => $isLateBooking,
            'days_until_event'   => $daysUntilEvent,
            'is_within_cancellation_cutoff' => $isWithinCancellationCutoff,
            'cancellation_cutoff_days'      => $cancellationCutoffDays,

            // ⭐ REQUEST #10: red-highlight flags
            'deposit_deadline_passed' => $depositDeadlinePassed,
            'requires_deposit_alert'  => $depositDeadlinePassed,

            // ⭐ REQUEST #11, #12, #13: 3-day warning for non-approved bookings
            'requires_three_day_warning' => $requiresThreeDayWarning,
            'three_day_warning_message'  => $threeDayWarningMessage,

            'deposit_percentage' => app(\App\Services\BookingPolicyService::class)->depositPercentage(),
            'required_deposit_amount' => (function () use ($totalAmount) {
                $pct = app(\App\Services\BookingPolicyService::class)->depositPercentage();
                return round($totalAmount * ($pct / 100), 2);
            })(),

            'assigned_staff'          => $assignedStaff,
            'assigned_staff_count'    => $assignedStaff->count(),
            'total_staff_required'    => $assignedStaff->count(),
            'event_completed'         => (bool) ($completionMetadata['event_completed'] ?? false),
            'event_done'              => (bool) ($ongoingMetadata['event_done'] ?? false),
            'event_done_at'           => $ongoingMetadata['event_done_at'] ?? null,
            'progress'                => (int) ($ongoingTracking?->progress_percentage ?? 0),
            'debt_booking_event'      => (bool) ($completionMetadata['debt_booking_event'] ?? false),
            'was_debt_booking_event'  => (bool) ($completionMetadata['was_debt_booking_event'] ?? $completionMetadata['debt_booking_event'] ?? false),
            'completion_override_reason' => $completionMetadata['override_reason'] ?? null,
            'outstanding_balance'     => max(0, $totalAmount - $paidAmount),

            'menu_items' => $bookingItems->map(fn($item) => [
                'id'                   => $item->booking_item_id,
                'meal_service_id'      => $item->meal_service_id ?? null,
                'meal_type'            => $item->mealService?->meal_type,
                'service_date'         => $item->mealService?->service_date?->toDateString(),
                'name'                 => $item->custom_item_name ?? $item->menuItem?->name ?? 'Menu item',
                'description'          => $item->description,
                'quantity'             => (int) $item->quantity,
                'total_quantity'       => (int) $item->quantity,
                'price'                => (float) $item->unit_price,
                'total_price'          => (float) $item->unit_price * (int) $item->quantity,
                'special_instructions' => $item->special_instructions,
            ])->values(),
            'event_days'    => $eventDays,
            'meal_services' => $mealServices,
            'meal_schedule' => $mealServices,

            'billing_summary' => [
                'total_meal_amount' => (float) $mealServices->sum('total_meal_amount'),
                'charges'           => $chargeRows->where('charge_kind', 'charge')->values()->map(fn($charge) => [
                    'charge_type' => $charge->charge_type,
                    'description' => $charge->description,
                    'amount'      => (float) $charge->amount,
                ]),
                'discounts'         => $chargeRows->where('charge_kind', 'discount')->values()->map(fn($charge) => [
                    'charge_type' => $charge->charge_type,
                    'description' => $charge->description,
                    'amount'      => (float) $charge->amount,
                ]),
                'additional_charges' => (float) $chargeRows->where('charge_kind', 'charge')->sum('amount'),
                'discount'          => (float) $chargeRows->where('charge_kind', 'discount')->sum('amount'),
                'grand_total'       => $totalAmount,
                'down_payment'      => (float) $payments->where('payment_type', 'deposit')->where('status', 'completed')->sum('amount'),
                'remaining_balance' => max(0, $totalAmount - $paidAmount),
                'payment_status'    => $paidAmount <= 0 ? 'pending' : ($paidAmount < $totalAmount ? 'partial' : 'paid'),
            ],

            'package_summary' => $event?->package ? [
                'name'                => $event->package->name,
                'description'         => $event->package->description,
                'base_price_per_pax'  => (float) $event->package->base_price_per_pax,
                'total_menu_items'    => $bookingItems->count(),
            ] : null,

            'order'     => $booking->order,
            'invoice'   => $booking->invoice,
            'equipment' => $equipmentRows,
            'tracking'  => $trackingRows,
        ];
    }

    private function paginateCollection(Collection $items, Request $request): LengthAwarePaginator
    {
        $page    = max(1, $request->integer('page', 1));
        $perPage = max(1, min(100, $request->integer('per_page', 20)));
        $items   = $items->values();
        return new LengthAwarePaginator(
            $items->slice(($page - 1) * $perPage, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
    }

    /**
     * ⭐ Create a base invoice for a booking that doesn't have one yet.
     * Mirrors the InvoiceController::store defaults so the row looks the
     * same no matter which endpoint created it.
     *
     * The total is derived from, in priority order:
     *   1. quotation.total_amount
     *   2. sum of booking_items (unit_price × quantity)
     *   3. sum of meal_services (pax × price_per_head)
     */
    private function createInvoiceForBooking(Booking $booking): \App\Models\Invoice
    {
        $booking->loadMissing(['quotation', 'items', 'mealServices', 'charges']);

        // ── 1. Subtotal.
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

        // ── 2. Additional charges.
        $additionalCharges = 0.0;
        if ($booking->relationLoaded('charges')) {
            $additionalCharges = (float) $booking->charges
                ->where('charge_kind', 'charge')
                ->sum('amount');
        } elseif (Schema::hasTable('booking_charges')) {
            $additionalCharges = (float) DB::table('booking_charges')
                ->where('booking_id', $booking->booking_id)
                ->where('charge_kind', 'charge')
                ->sum('amount');
        }

        // ── 3. Discounts.
        $discount = 0.0;
        if ($booking->relationLoaded('charges')) {
            $discount = (float) $booking->charges
                ->where('charge_kind', 'discount')
                ->sum('amount');
        } elseif (Schema::hasTable('booking_charges')) {
            $discount = (float) DB::table('booking_charges')
                ->where('booking_id', $booking->booking_id)
                ->where('charge_kind', 'discount')
                ->sum('amount');
        }

        // ── 4. Grand total.
        $totalAmount = max(0, $subtotal + $additionalCharges - $discount);

        $invoiceNumber = \App\Models\Invoice::nextInvoiceNumber();

        return \App\Models\Invoice::create([
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
    }
    private function synchronizeBookingInvoice(Booking $booking): void
    {
        $booking->loadMissing('invoice');
        if (! $booking->invoice) {
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
        $total   = (float) $booking->invoice->total_amount;
        $status  = $netPaid >= $total && $total > 0 ? 'paid' : ($netPaid > 0 ? 'partial' : 'unpaid');
        if ($status !== 'paid' && $booking->invoice->due_date?->isPast()) {
            $status = 'overdue';
        }

        $booking->invoice->update([
            'paid_amount' => $netPaid,
            'status'      => $status,
        ]);
    }

    public function availability(Request $request): JsonResponse
    {
        return $this->getAvailability($request);
    }

    private function handleConfirmedBooking(Booking $booking): void
    {
        try {
            $service = app(\App\Services\BookingService::class);

            if (method_exists($service, 'createOrderFromBooking')) {
                $service->createOrderFromBooking($booking);
            }
            if (method_exists($service, 'createKitchenPreparation')) {
                $service->createKitchenPreparation($booking);
            }
            if (method_exists($service, 'createDeliveryPreparation')) {
                $service->createDeliveryPreparation($booking);
            }
            if (method_exists($service, 'createIngredientsManagement')) {
                $service->createIngredientsManagement($booking);
            }
        } catch (\Exception $e) {
            \Log::error('Auto-confirm booking failed: ' . $e->getMessage());
        }
    }
}
