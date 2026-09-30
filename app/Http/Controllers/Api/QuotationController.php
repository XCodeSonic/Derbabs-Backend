<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\Booking;
use App\Models\ChatMessage;
use App\Models\Notification;
use App\Models\Person;
use App\Models\Quotation;
use App\Models\ServiceEvent;
use App\Services\NotificationService;
use App\Services\QuotationDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuotationController extends Controller
{
    protected $notificationService;
    protected $quotationDeliveryService;

    public function __construct(
        NotificationService $notificationService,
        QuotationDeliveryService $quotationDeliveryService
    ) {
        $this->notificationService = $notificationService;
        $this->quotationDeliveryService = $quotationDeliveryService;
    }

    public function index(Request $request): JsonResponse
    {
        $query = Quotation::query()
            ->with(['serviceEvent.customer.person', 'serviceEvent.eventType', 'booking'])
            ->latest('quotation_id');

        if ($request->filled('status_in')) {
            $statuses = collect(explode(',', (string) $request->input('status_in')))
                ->map(fn ($status) => trim($status))
                ->filter()
                ->values()
                ->all();
            if (! empty($statuses)) {
                $query->whereIn('status', $statuses);
            }
        } elseif ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        } else {
            $query->where('status', 'pending');
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('quote_no', 'like', "%{$search}%")
                    ->orWhereHas('serviceEvent.customer.person', fn ($person) => $person
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $rows = $query->paginate($request->integer('per_page', 20));
        $rows->setCollection($rows->getCollection()->map(fn (Quotation $quotation) => $this->formatQuotation($quotation)));

        return $this->ok($rows);
    }

    public function show(Quotation $quotation): JsonResponse
    {
        return $this->ok($this->formatQuotation($quotation->load(['serviceEvent.customer.person', 'serviceEvent.eventType', 'booking'])));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_id' => ['required', 'exists:bookings,booking_id'],
            'customer_name' => ['nullable', 'string', 'max:160'],
            'customer_email' => ['nullable', 'email', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'customer_address' => ['nullable', 'string'],
            'event_type_id' => ['nullable', 'exists:event_types,event_type_id'],
            'event_date' => ['nullable', 'date'],
            'event_time' => ['nullable', 'string', 'max:50'],
            'venue' => ['nullable', 'string', 'max:500'],
            'guests_count' => ['nullable', 'integer', 'min:1'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'special_requests' => ['nullable', 'string'],
        ]);

        $booking = Booking::with(['serviceEvent.customer.person', 'serviceEvent.eventType'])->findOrFail($validated['booking_id']);
        if (! in_array(strtolower((string) $booking->booking_status), ['approved', 'confirmed'], true)) {
            return $this->fail('Quotation can only be created for approved bookings.', 422);
        }

        $quotation = DB::transaction(function () use ($validated, $booking): Quotation {
            if ($booking->service_event_id) {
                $event = $booking->serviceEvent;
            } else {
                $name = $validated['customer_name'] ?? 'Customer Account';
                $email = $validated['customer_email'] ?? ('customer-' . $booking->booking_id . '@example.local');
                $nameParts = preg_split('/\s+/', trim($name)) ?: [];
            $firstName = array_shift($nameParts) ?: 'Customer';
            $lastName = implode(' ', $nameParts) ?: 'Account';

            $person = Person::query()->firstOrCreate(
                    ['email' => $email],
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $validated['customer_phone'] ?? null,
                    'address_line_1' => $validated['customer_address'] ?? null,
                ]
            );

            $customer = Customer::query()->firstOrCreate(
                ['person_id' => $person->person_id],
                [
                    'customer_code' => 'CUS-' . strtoupper(Str::random(8)),
                    'is_active' => true,
                ]
            );

            $event = ServiceEvent::query()->create([
                'customer_id' => $customer->customer_id,
                    'event_type_id' => $validated['event_type_id'] ?? 1,
                    'event_date' => $validated['event_date'] ?? now()->toDateString(),
                    'event_time' => $validated['event_time'] ?? '08:00',
                    'venue' => $validated['venue'] ?? 'To be confirmed',
                    'guests_count' => $validated['guests_count'] ?? 1,
                'service_type' => 'buffet',
                'menu_selection_type' => 'custom',
                'has_waiters' => false,
                'delivery_method' => 'delivery',
                'special_requests' => $validated['special_requests'] ?? null,
                'status' => 'pending',
            ]);
            }

            $quotation = Quotation::query()->updateOrCreate(
                ['service_event_id' => $event->service_event_id],
                [
                    'quote_no' => $booking->quotation?->quote_no ?? 'QTN-' . now()->format('YmdHis') . '-' . $event->service_event_id,
                'service_event_id' => $event->service_event_id,
                'total_amount' => $validated['total_amount'],
                'status' => 'pending',
                'valid_until' => now()->addDays(7)->toDateString(),
                ]
            );

            $booking->update(['quotation_id' => $quotation->quotation_id]);

            return $quotation;
        });

        // ✅ Notification: New quotation request
        $this->notificationService->quotationRequested($quotation);
        $this->quotationDeliveryService->send($quotation->fresh(['serviceEvent.customer.person', 'serviceEvent.eventType', 'booking']));

        return $this->ok($this->formatQuotation($quotation->fresh(['serviceEvent.customer.person', 'serviceEvent.eventType', 'booking'])), 'Quotation created and sent to the customer.');
    }

    public function send(Quotation $quotation): JsonResponse
    {
        $this->quotationDeliveryService->send($quotation->load(['serviceEvent.customer.person', 'serviceEvent.eventType', 'booking']));
        $quotation->update(['status' => 'approved']);

        return $this->ok($this->formatQuotation($quotation->fresh(['serviceEvent.customer.person', 'serviceEvent.eventType', 'booking'])), 'Quotation sent to the customer.');
    }

    public function update(Request $request, Quotation $quotation): JsonResponse
    {
        $quotation->update($request->only(['total_amount', 'status', 'valid_until']));

        return $this->ok($this->formatQuotation($quotation->fresh(['serviceEvent.customer.person', 'serviceEvent.eventType', 'booking'])), 'Quotation updated.');
    }

    public function destroy(Quotation $quotation): JsonResponse
    {
        $quotation->delete();

        return $this->ok(null, 'Quotation deleted.');
    }

    public function approve(Quotation $quotation): JsonResponse
    {
        $quotation->update(['status' => 'approved']);
        
        // Get the booking associated with this quotation
        $booking = $quotation->booking;
        
        // ✅ Notification: Customer accepted quotation
        if ($booking) {
            $this->notificationService->quotationAccepted($quotation, $booking);
        }

        return $this->ok($this->formatQuotation($quotation->fresh(['serviceEvent.customer.person', 'serviceEvent.eventType', 'booking'])), 'Quotation approved.');
    }

    private function formatQuotation(Quotation $quotation): array
    {
        $quotation->loadMissing(['serviceEvent.customer.person', 'serviceEvent.eventType', 'booking']);

        $event = $quotation->serviceEvent;
        $person = $event?->customer?->person;
        $booking = $quotation->booking;
        $sendHistory = $this->quotationSendHistory($quotation);

        return [
            'id' => $quotation->quotation_id,
            'quotation_id' => $quotation->quotation_id,
            'quote_no' => $quotation->quote_no,
            'status' => $quotation->status,
            'valid_until' => optional($quotation->valid_until)->toDateString(),
            'total_amount' => (float) $quotation->total_amount,
            'booking_id' => $booking?->booking_id,
            'booking_no' => $booking?->booking_no,
            'booking_status' => $booking?->booking_status,
            'customer_name' => trim(($person?->first_name ?? '') . ' ' . ($person?->last_name ?? '')),
            'customer_email' => $person?->email,
            'customer_phone' => $person?->phone,
            'event_type_id' => $event?->event_type_id,
            'event_date' => optional($event?->event_date)->toDateString(),
            'event_time' => $event?->event_time,
            'venue' => $event?->venue,
            'guests_count' => (int) ($event?->guests_count ?? 0),
            'send_history' => $sendHistory,
            'latest_send' => $sendHistory[0] ?? null,
        ];
    }

    private function quotationSendHistory(Quotation $quotation): array
    {
        $history = Notification::query()
            ->where('type', 'quotation_sent')
            ->where(function ($query) use ($quotation) {
                $query->where('data->quotation_id', $quotation->quotation_id)
                    ->orWhere('data->quote_no', $quotation->quote_no);
            })
            ->latest('notification_id')
            ->limit(10)
            ->get()
            ->map(function (Notification $notification) {
                $sentAt = $notification->sent_at ?? $notification->created_at;
                $data = is_array($notification->data) ? $notification->data : [];

                return [
                    'date_sent' => $sentAt?->format('Y-m-d'),
                    'time_sent' => $sentAt?->format('h:i A'),
                    'delivery_status' => $data['delivery_status'] ?? ($notification->is_sent ? 'Sent' : 'Pending'),
                    'gmail_status' => $data['gmail_delivery_status'] ?? 'not_attempted',
                    'messenger_status' => $data['messenger_delivery_status'] ?? 'not_attempted',
                ];
            })
            ->values()
            ->all();

        if (! empty($history)) {
            return $history;
        }

        // Fallback when the customer has no linked user account but the mobile chat message was created.
        return ChatMessage::query()
            ->where('message', 'like', '%' . $quotation->quote_no . '%')
            ->latest('message_id')
            ->limit(10)
            ->get()
            ->map(function (ChatMessage $message) {
                return [
                    'date_sent' => $message->created_at?->format('Y-m-d'),
                    'time_sent' => $message->created_at?->format('h:i A'),
                    'delivery_status' => 'Sent',
                    'gmail_status' => 'not_recorded',
                    'messenger_status' => 'sent',
                ];
            })
            ->values()
            ->all();
    }


}