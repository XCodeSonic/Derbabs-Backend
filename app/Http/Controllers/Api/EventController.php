<?php

namespace App\Http\Controllers\Api;

use App\Models\Booking;
use App\Models\BookingEquipment;
use App\Models\Employee;
use App\Models\EventTracking;
use App\Models\EventChecklistItem;
use App\Models\EventDeliveryTracking;
use App\Models\Schedule;
use App\Models\ServiceEvent;
use App\Models\ShiftType;
use Carbon\Carbon;
use App\Models\Setting;
use App\Models\MealService;
use App\Services\EventService;
use App\Services\InventoryService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EventController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    private function query()
    {
        $relations = [
            'serviceEvent.customer.person',
            'serviceEvent.eventType',
            'serviceEvent.package',
            'items.menuItem',
            'payments',
            'equipment.equipment',
            'tracking',
            'order',
            'invoice',
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
        }

        if (Schema::hasTable('event_checklist_items')) {
            $relations[] = Schema::hasTable('meal_services') && Schema::hasTable('event_days')
                ? 'eventChecklistItems.mealService.eventDay'
                : 'eventChecklistItems';
        }

        if (Schema::hasTable('event_delivery_trackings')) {
            $relations[] = Schema::hasTable('meal_services') && Schema::hasTable('event_days')
                ? 'deliveryTrackings.mealService.eventDay'
                : 'deliveryTrackings';
        }

        return Booking::with($relations);
    }

    public function index(Request $request)
    {
        $query = $this->query()
            ->whereIn('booking_status', ['confirmed', 'ongoing'])
            ->whereHas('serviceEvent', fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled']));

        $requestedStatus = strtolower((string) $request->input('status', ''));
        if ($requestedStatus !== '' && $requestedStatus !== 'all') {
            $query->where('booking_status', $requestedStatus)
                ->whereHas('serviceEvent', fn ($q) => $q->where('status', $requestedStatus));
        }

        if ($request->boolean('ongoing_only')) {
            $query->where('booking_status', 'ongoing')
                ->whereHas('serviceEvent', fn ($q) => $q->where('status', 'ongoing'));
        }

        if ($request->event_date) {
            $query->whereHas('serviceEvent', fn ($q) => $q->whereDate('event_date', $request->event_date));
        }

        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('booking_no', 'like', "%{$search}%")
                    ->orWhereHas('serviceEvent.customer.person', fn ($p) =>
                        $p->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                    );
            });
        }

        $events = $query->latest('booking_id')->paginate($request->integer('per_page', 15));
        $events->getCollection()->transform(fn ($booking) => $this->formatEvent($booking));

        return $this->ok($events);
    }

    public function show($id)
    {
        $booking = $this->query()->findOrFail($id);
        return $this->ok($this->formatEvent($booking));
    }

    public function stats()
    {
        $operational = Booking::query()->where('booking_no', 'not like', 'HIST-%');
        $total = (clone $operational)->whereIn('booking_status', ['confirmed', 'ongoing', 'completed'])->count();
        $upcoming = (clone $operational)->whereHas('serviceEvent', fn($q) => $q->whereDate('event_date', '>=', now()))
            ->whereIn('booking_status', ['confirmed'])
            ->count();
        $ongoing = (clone $operational)->whereHas('serviceEvent', fn($q) => $q->whereDate('event_date', '<=', now()))
            ->whereIn('booking_status', ['ongoing'])
            ->count();
        $completed = (clone $operational)->where('booking_status', 'completed')->count();
        
        return $this->ok([
            'total' => $total,
            'upcoming' => $upcoming,
            'ongoing' => $ongoing,
            'completed' => $completed,
        ]);
    }

    /**
     * Send event reminders for tomorrow's events
     */
    public function sendEventReminders()
    {
        $tomorrow = now()->addDay()->toDateString();
        
        $events = Booking::whereHas('serviceEvent', function($q) use ($tomorrow) {
            $q->whereDate('event_date', $tomorrow);
        })->whereIn('booking_status', ['confirmed', 'ongoing'])->get();
        
        $remindersSent = 0;
        
        foreach ($events as $booking) {
            // ✅ Notification: Event starts tomorrow for Event Coordinator
            $this->notificationService->eventStartsTomorrow($booking);
            $remindersSent++;
            
            // Also check equipment needs
            foreach ($booking->equipment as $equipmentItem) {
                if ($equipmentItem->equipment) {
                    $this->notificationService->equipmentPreparationNeeded($booking, $equipmentItem->equipment);
                }
            }
            
            // Check delivery preparation
            if ($booking->serviceEvent?->delivery_method === 'delivery') {
                $this->notificationService->deliveryPreparationReady($booking);
            }
        }
        
        return $this->ok(['sent' => $remindersSent], 'Event reminders sent');
    }

    public function assignStaff(Request $request, $id)
    {
        $validated = $request->validate([
            'staff_id' => 'nullable|exists:employees,employee_id',
            'staff_ids' => 'nullable|array',
            'staff_ids.*' => 'exists:employees,employee_id',
            'role' => 'required|string|max:100',
            'shift_type_id' => 'nullable|exists:shift_types,shift_type_id',
            'schedule' => 'nullable|string|max:100',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
        ]);

        $staffIds = collect($validated['staff_ids'] ?? $validated['staff_id'] ?? [])
            ->flatten()
            ->filter()
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values();

        if ($staffIds->isEmpty()) {
            return $this->fail('Please select at least one staff member.', 422);
        }

        $booking = Booking::with(['serviceEvent', 'payments', 'invoice', 'quotation'])->findOrFail($id);
        $eventDate = $booking->serviceEvent?->event_date;
        if (! $eventDate) {
            return $this->fail('Cannot assign staff because this booking has no scheduled event date.', 422);
        }

        [$parsedStart, $parsedEnd] = $this->parseScheduleWindow($validated['schedule'] ?? null);
        $shiftType = $this->resolveEventShiftType($validated['shift_type_id'] ?? null, $parsedStart, $parsedEnd);
        $startTime = $validated['start_time'] ?? (! empty($validated['schedule']) ? $parsedStart : substr((string) $shiftType->default_start_time, 0, 5));
        $endTime = $validated['end_time'] ?? (! empty($validated['schedule']) ? $parsedEnd : substr((string) $shiftType->default_end_time, 0, 5));
        $scheduleLabel = "{$startTime} - {$endTime}";
        $eventService = app(EventService::class);
        $employees = Employee::whereIn('employee_id', $staffIds)->get()->keyBy('employee_id');

        DB::transaction(function () use ($booking, $validated, $staffIds, $employees, $eventService, $eventDate, $startTime, $endTime, $scheduleLabel, $shiftType) {
            $assignments = $staffIds->map(fn ($staffId) => [
                'staff_id' => $staffId,
                'role' => $validated['role'],
                'schedule' => $scheduleLabel,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,
            ])->all();

            $eventService->assignStaffToEvent($booking, $assignments);

            $startDate = Carbon::parse($eventDate)->startOfDay();
            $endDate = $booking->serviceEvent?->event_end_date
                ? Carbon::parse($booking->serviceEvent->event_end_date)->startOfDay()
                : $startDate->copy();

            foreach ($staffIds as $staffId) {
                $employee = $employees->get($staffId);
                for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
                    $schedule = Schedule::withTrashed()
                        ->where('employee_id', $staffId)
                        ->whereDate('work_date', $date->toDateString())
                        ->first();

                    if (! $schedule) {
                        $schedule = new Schedule([
                            'employee_id' => $staffId,
                            'work_date' => $date->toDateString(),
                        ]);
                    } else {
                        $assignmentPayload = json_decode((string) $schedule->assignment_details, true) ?: [];
                        $existingBookingId = Schema::hasColumn('schedules', 'booking_id')
                            ? $schedule->booking_id
                            : ($assignmentPayload['booking_id'] ?? null);

                        if ($existingBookingId && (int) $existingBookingId !== (int) $booking->booking_id) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'staff_ids' => "Employee {$staffId} already has a schedule for another event on {$date->toDateString()}.",
                            ]);
                        }

                        if ($schedule->trashed()) {
                            $schedule->restore();
                        }
                    }

                    $payload = [
                        'shift_type_id' => (int) $shiftType->shift_type_id,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'break_minutes' => (float) ($shiftType->break_minutes ?? 0),
                        'assignment_details' => json_encode([
                            'placement' => $booking->serviceEvent?->venue,
                            'notes' => "Assigned to event: {$booking->booking_no} - Role: {$validated['role']}",
                            'booking_id' => $booking->booking_id,
                            'role' => $validated['role'],
                        ]),
                        'status' => 'scheduled',
                    ];

                    if (Schema::hasColumn('schedules', 'booking_id')) {
                        $payload['booking_id'] = $booking->booking_id;
                    }

                    $schedule->fill($payload)->save();

                    if ($employee?->user_id) {
                        try {
                            $this->notificationService->scheduleAssigned($schedule, $employee);
                        } catch (\Throwable $exception) {
                            Log::warning('Staff assignment notification failed', [
                                'booking_id' => $booking->booking_id,
                                'employee_id' => $staffId,
                                'message' => $exception->getMessage(),
                            ]);
                        }
                    }
                }
            }
        });

        return $this->ok($eventService->getAssignedStaff($booking->fresh()), 'Staff assigned and schedule created successfully');
    }

    private function resolveEventShiftType(?int $shiftTypeId, string $startTime, string $endTime): ShiftType
    {
        if ($shiftTypeId) {
            return ShiftType::where('is_active', true)->findOrFail($shiftTypeId);
        }

        $existing = ShiftType::where('is_active', true)
            ->where(function ($query) {
                $query->where('slug', 'regular')->orWhere('name', 'Regular');
            })
            ->first() ?? ShiftType::where('is_active', true)->orderBy('shift_type_id')->first();

        if ($existing) {
            return $existing;
        }

        return ShiftType::firstOrCreate(
            ['slug' => 'event-regular'],
            [
                'name' => 'Event Regular',
                'default_start_time' => $startTime,
                'default_end_time' => $endTime,
                'break_minutes' => 60,
                'night_differential_rate' => 0,
                'is_active' => true,
            ]
        );
    }

    private function parseScheduleWindow(?string $schedule): array
    {
        $normalize = function (?string $time, string $fallback): string {
            $time = trim((string) $time);
            if ($time === '') {
                return $fallback;
            }
            try {
                return Carbon::parse($time)->format('H:i');
            } catch (\Throwable) {
                return $fallback;
            }
        };

        $schedule = trim((string) $schedule);
        if ($schedule && str_contains($schedule, '-')) {
            [$start, $end] = array_pad(array_map('trim', explode('-', $schedule, 2)), 2, null);
            return [$normalize($start, '08:00'), $normalize($end, '17:00')];
        }

        return ['08:00', '17:00'];
    }

    public function getStaff($id)
    {
        $booking = Booking::findOrFail($id);
        $eventService = app(EventService::class);
        
        return $this->ok($eventService->getAssignedStaff($booking));
    }

    public function updateStaffStatus(Request $request, $id, $staffId)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,confirmed,declined',
        ]);
        
        $booking = Booking::findOrFail($id);
        $tracking = EventTracking::where('booking_id', $booking->booking_id)
            ->where('stage', 'preparation')
            ->first();
        
        if ($tracking && $tracking->notes) {
            $metadata = json_decode($tracking->notes, true);
            $metadata = is_array($metadata) ? $metadata : [];
            foreach (($metadata['assigned_staff'] ?? []) as &$staff) {
                if ($staff['staff_id'] == $staffId) {
                    $staff['status'] = $validated['status'];
                    break;
                }
            }
            $tracking->update(['notes' => json_encode($metadata)]);
        }
        
        return $this->ok(null, 'Staff status updated');
    }

    public function removeStaff($id, $staffId)
    {
        $booking = Booking::findOrFail($id);
        $tracking = EventTracking::where('booking_id', $booking->booking_id)
            ->where('stage', 'preparation')
            ->first();
        
        if ($tracking && $tracking->notes) {
            $metadata = json_decode($tracking->notes, true);
            $metadata['assigned_staff'] = array_filter($metadata['assigned_staff'] ?? [], fn($s) => $s['staff_id'] != $staffId);
            $tracking->update(['notes' => json_encode($metadata)]);
        }
        
        // Remove only schedules created for this event.
        $scheduleQuery = Schedule::where('employee_id', $staffId);
        if (Schema::hasColumn('schedules', 'booking_id')) {
            $scheduleQuery->where('booking_id', $booking->booking_id);
        } else {
            $scheduleQuery->where('assignment_details', 'like', '%"booking_id":' . $booking->booking_id . '%');
        }
        $scheduleQuery->delete();
        
        // ✅ Notification: Staff removed from event
        $employee = Employee::find($staffId);
        if ($employee && $employee->user_id) {
            $eventDate = $booking->serviceEvent?->event_date;
            $eventDateLabel = $eventDate ? Carbon::parse($eventDate)->format('M d, Y') : 'the scheduled date';
            $this->notificationService->notifyUser(
                $employee->user_id,
                'schedule_cancelled',
                'Schedule Removed',
                "Your assignment for event {$booking->booking_no} on {$eventDateLabel} has been removed.",
                \App\Models\Notification::PRIORITY_MEDIUM,
                ['booking_id' => $booking->booking_id]
            );
        }
        
        return $this->ok(null, 'Staff removed');
    }

    public function getChecklist($id)
    {
        $booking = $this->query()->findOrFail($id);
        $rows = $this->ensureChecklistRows($booking);
        $warnings = $this->eventWarnings($booking);

        return $this->ok([
            'checklist' => $rows,
            'warnings' => $warnings,
            'has_warnings' => count($warnings) > 0,
            'warning_count' => count($warnings),
            'display_name' => $booking->serviceEvent?->customer?->person?->full_name . ' - ' . ($booking->serviceEvent?->eventType?->name ?? 'Event'),
            'customer_name' => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
            'event_name' => $booking->serviceEvent?->eventType?->name ?? 'Event',
        ]);
    }

    public function updateChecklistItem(Request $request, $id, $itemId)
    {
        $validated = $request->validate([
            'completed' => 'nullable|boolean',
            'status' => 'nullable|in:pending,in_progress,completed',
            'notes' => 'nullable|string',
        ]);

        if (! array_key_exists('completed', $validated) && ! array_key_exists('status', $validated) && ! array_key_exists('notes', $validated)) {
            return $this->fail('Provide a completed state, status, or notes to update.', 422);
        }

        $booking = Booking::findOrFail($id);
        $item = EventChecklistItem::where('booking_id', $booking->booking_id)
            ->where(function ($q) use ($itemId) {
                $q->where('event_checklist_item_id', $itemId)->orWhere('task_key', $itemId);
            })
            ->firstOrFail();

        $updates = [];
        if (array_key_exists('completed', $validated)) {
            $updates['status'] = $validated['completed'] ? 'completed' : 'pending';
        } elseif (array_key_exists('status', $validated)) {
            $updates['status'] = $validated['status'];
        }
        if (array_key_exists('notes', $validated)) {
            $updates['notes'] = $validated['notes'];
        }
        if (Schema::hasColumn('event_checklist_items', 'manual_override')) {
            $updates['manual_override'] = true;
        }
        if (array_key_exists('status', $updates) && Schema::hasColumn('event_checklist_items', 'completed_at')) {
            $updates['completed_at'] = $updates['status'] === 'completed' ? now() : null;
        }
        if (array_key_exists('status', $updates) && Schema::hasColumn('event_checklist_items', 'completed_by')) {
            $updates['completed_by'] = $updates['status'] === 'completed' ? auth()->id() : null;
        }

        $item->update($updates);
        $freshBooking = $this->query()->findOrFail($id);

        return $this->ok([
            'item' => $this->formatChecklistRows(collect([$item->fresh()]))[0] ?? null,
            'checklist' => $this->ensureChecklistRows($freshBooking),
        ], 'Checklist updated');
    }

    public function addChecklistItem(Request $request, $id)
    {
        $validated = $request->validate([
            'task' => 'required|string|max:255',
            'assigned_to' => 'nullable|string|max:120',
            'meal_service_id' => 'nullable|exists:meal_services,meal_service_id',
            'notes' => 'nullable|string',
        ]);

        $booking = Booking::findOrFail($id);
        $item = EventChecklistItem::create([
            'booking_id' => $booking->booking_id,
            'meal_service_id' => $validated['meal_service_id'] ?? null,
            'task_key' => 'manual_' . now()->timestamp . '_' . random_int(100, 999),
            'task' => $validated['task'],
            'assigned_to' => $validated['assigned_to'] ?? 'Unassigned',
            'status' => 'pending',
            'source_type' => 'manual',
            'notes' => $validated['notes'] ?? null,
        ]);

        return $this->ok($item, 'Checklist item added');
    }

    public function deleteChecklistItem($id, $itemId)
    {
        $booking = Booking::findOrFail($id);
        EventChecklistItem::where('booking_id', $booking->booking_id)
            ->where(function ($q) use ($itemId) {
                $q->where('event_checklist_item_id', $itemId)->orWhere('task_key', $itemId);
            })
            ->delete();

        return $this->ok(null, 'Checklist item deleted');
    }

    public function getDeliveries($id)
    {
        $booking = $this->query()->findOrFail($id);
        return $this->ok($this->ensureDeliveryRows($booking));
    }

    public function updateDeliveryStatus(Request $request, $id, $deliveryId)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,preparing,departed,en_route,arrived,serving,completed,cancelled',
            'location' => 'nullable|string',
            'return_time' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        $booking = Booking::findOrFail($id);
        $delivery = EventDeliveryTracking::where('booking_id', $booking->booking_id)
            ->where('event_delivery_tracking_id', $deliveryId)
            ->firstOrFail();
        $metadata = $this->deliveryMetadata($delivery->notes);
        if (array_key_exists('notes', $validated)) {
            $metadata['notes'] = $validated['notes'];
        }

        $delivery->update([
            'status' => $validated['status'],
            'venue' => $validated['location'] ?? $delivery->venue,
            'return_time' => $validated['return_time'] ?? $delivery->return_time,
            'notes' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        if ($delivery->meal_service_id) {
            $mealUpdate = ['delivery_status' => $validated['status']];
            if (in_array($validated['status'], ['arrived', 'serving', 'completed'], true)) {
                $mealUpdate['meal_status'] = $validated['status'] === 'completed' ? 'completed' : 'delivered';
            }
            MealService::where('meal_service_id', $delivery->meal_service_id)->update($mealUpdate);
        }

        return $this->ok($delivery->fresh(), 'Delivery status updated');
    }

    public function addDelivery(Request $request, $id)
    {
        $validated = $request->validate([
            'meal_service_id' => 'nullable|exists:meal_services,meal_service_id',
            'delivery_type' => 'nullable|string|max:50',
            'delivery_date' => 'nullable|date',
            'delivery_time' => 'nullable|string|max:50',
            'return_time' => 'nullable|string|max:50',
            'venue' => 'nullable|string|max:255',
            'driver' => 'nullable|string|max:120',
            'driver_phone' => 'nullable|string|max:40',
            'vehicle' => 'nullable|string|max:120',
            'items' => 'nullable|string|max:5000',
            'notes' => 'nullable|string',
        ]);

        $booking = Booking::with(['serviceEvent', 'mealServices.eventDay'])->findOrFail($id);
        $meal = !empty($validated['meal_service_id'])
            ? $booking->mealServices->firstWhere('meal_service_id', (int) $validated['meal_service_id'])
            : null;

        $delivery = EventDeliveryTracking::create([
            'booking_id' => $booking->booking_id,
            'meal_service_id' => $meal?->meal_service_id,
            'delivery_type' => $validated['delivery_type'] ?? $booking->serviceEvent?->service_type ?? 'food',
            'delivery_date' => $validated['delivery_date'] ?? $meal?->service_date?->toDateString() ?? $booking->serviceEvent?->event_date?->toDateString(),
            'delivery_time' => $validated['delivery_time'] ?? $meal?->dispatch_time,
            'return_time' => $validated['return_time'] ?? null,
            'venue' => $validated['venue'] ?? $booking->serviceEvent?->venue,
            'driver' => $validated['driver'] ?? null,
            'driver_phone' => $validated['driver_phone'] ?? null,
            'status' => 'pending',
            'notes' => json_encode([
                'vehicle' => $validated['vehicle'] ?? null,
                'items' => $validated['items'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $this->ok($this->formatDeliveryRows(collect([$delivery->fresh('mealService.eventDay')]))[0] ?? null, 'Delivery added successfully');
    }

    public function updateDelivery(Request $request, $id, $deliveryId)
    {
        $validated = $request->validate([
            'meal_service_id' => 'nullable|exists:meal_services,meal_service_id',
            'delivery_type' => 'nullable|string|max:50',
            'delivery_date' => 'nullable|date',
            'delivery_time' => 'nullable|string|max:50',
            'return_time' => 'nullable|string|max:50',
            'venue' => 'nullable|string|max:255',
            'driver' => 'nullable|string|max:120',
            'driver_phone' => 'nullable|string|max:40',
            'vehicle' => 'nullable|string|max:120',
            'items' => 'nullable|string|max:5000',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:pending,preparing,departed,en_route,arrived,serving,completed,cancelled',
        ]);

        $booking = Booking::findOrFail($id);
        $delivery = EventDeliveryTracking::where('booking_id', $booking->booking_id)
            ->where('event_delivery_tracking_id', $deliveryId)
            ->firstOrFail();
        $metadata = $this->deliveryMetadata($delivery->notes);
        foreach (['vehicle', 'items', 'notes'] as $field) {
            if (array_key_exists($field, $validated)) {
                $metadata[$field] = $validated[$field];
            }
        }

        $delivery->update(array_filter([
            'meal_service_id' => $validated['meal_service_id'] ?? $delivery->meal_service_id,
            'delivery_type' => $validated['delivery_type'] ?? $delivery->delivery_type,
            'delivery_date' => $validated['delivery_date'] ?? $delivery->delivery_date,
            'delivery_time' => $validated['delivery_time'] ?? $delivery->delivery_time,
            'return_time' => $validated['return_time'] ?? $delivery->return_time,
            'venue' => $validated['venue'] ?? $delivery->venue,
            'driver' => $validated['driver'] ?? $delivery->driver,
            'driver_phone' => $validated['driver_phone'] ?? $delivery->driver_phone,
            'status' => $validated['status'] ?? $delivery->status,
            'notes' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], fn ($value) => $value !== null));

        return $this->ok(
            $this->formatDeliveryRows(collect([$delivery->fresh('mealService.eventDay')]))[0] ?? null,
            'Delivery updated successfully'
        );
    }

    public function deleteDelivery($id, $deliveryId)
    {
        $booking = Booking::findOrFail($id);
        $delivery = EventDeliveryTracking::where('booking_id', $booking->booking_id)
            ->where('event_delivery_tracking_id', $deliveryId)
            ->firstOrFail();
        $delivery->delete();

        return $this->ok(null, 'Delivery removed successfully');
    }

    public function getDailyProgress($id)
    {
        $booking = Booking::findOrFail($id);
        $eventService = app(EventService::class);
        
        return $this->ok($eventService->getMultiDayProgress($booking));
    }

    public function updateDailyProgress(Request $request, $id, $day)
    {
        $validated = $request->validate([
            'completion' => 'required|integer|min:0|max:100',
            'notes' => 'nullable|string',
            'attendance' => 'nullable|integer|min:0',
        ]);
        
        $booking = Booking::findOrFail($id);
        $eventService = app(EventService::class);
        $eventService->updateDailyProgress($booking, $day, $validated);
        
        // ✅ Notification: Daily progress milestone reached
        if ($validated['completion'] >= 100) {
            $customer = $booking->serviceEvent?->customer;
            if ($customer && $customer->user_id) {
                $this->notificationService->notifyUser(
                    $customer->user_id,
                    'day_completed',
                    "Day {$day} Completed",
                    "Day {$day} of your event {$booking->booking_no} has been completed successfully.",
                    \App\Models\Notification::PRIORITY_MEDIUM,
                    ['booking_id' => $booking->booking_id, 'day' => $day]
                );
            }
        }
        
        return $this->ok(null, 'Daily progress updated');
    }

    public function updateAttendance(Request $request, $id, $day)
    {
        $validated = $request->validate([
            'present' => 'required|integer|min:0',
        ]);
        
        $booking = Booking::findOrFail($id);
        $eventService = app(EventService::class);
        $eventService->updateAttendance($booking, $day, $validated['present']);
        
        return $this->ok(null, 'Attendance updated');
    }

    public function advanceToNextDay($id)
    {
        $booking = Booking::findOrFail($id);
        $eventService = app(EventService::class);
        $result = $eventService->advanceToNextDay($booking);
        
        // ✅ Notification: Next day advanced
        if ($result['success']) {
            $customer = $booking->serviceEvent?->customer;
            if ($customer && $customer->user_id) {
                $this->notificationService->notifyUser(
                    $customer->user_id,
                    'day_advanced',
                    'Event Day Advanced',
                    "Your event has advanced to day {$result['current_day']} of {$result['total_days']}.",
                    \App\Models\Notification::PRIORITY_MEDIUM,
                    ['booking_id' => $booking->booking_id, 'current_day' => $result['current_day']]
                );
            }
        }
        
        return $this->ok($result, $result['success'] ? 'Advanced to next day' : 'Cannot advance');
    }

    public function getSessions($id)
    {
        $booking = Booking::findOrFail($id);
        return $this->ok(app(EventService::class)->getSessions($booking));
    }

    public function addSession(Request $request, $id)
    {
        $validated = $request->validate([
            'day' => 'nullable|integer|min:1',
            'title' => 'required_without:name|string|max:150',
            'name' => 'required_without:title|string|max:150',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',
            'location' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:scheduled,in_progress,completed,cancelled',
        ]);

        $booking = Booking::findOrFail($id);
        return $this->ok(app(EventService::class)->addSession($booking, $validated), 'Session added successfully');
    }

    public function updateSessionStatus(Request $request, $id, $sessionId)
    {
        $validated = $request->validate([
            'status' => 'required|in:scheduled,in_progress,completed,cancelled',
        ]);

        $booking = Booking::findOrFail($id);
        return $this->ok(
            app(EventService::class)->updateSessionStatus($booking, (string) $sessionId, $validated['status']),
            'Session status updated'
        );
    }

    public function deleteSession($id, $sessionId)
    {
        $booking = Booking::findOrFail($id);
        app(EventService::class)->deleteSession($booking, (string) $sessionId);
        return $this->ok(null, 'Session deleted');
    }

    public function complete(Request $request, $id)
    {
        $validated = $request->validate([
            'force_complete' => 'nullable|boolean',
            'reason' => 'nullable|string|max:1000',
        ]);

        $booking = Booking::with(['serviceEvent', 'payments', 'invoice', 'quotation', 'order'])->findOrFail($id);
        $totalAmount = (float) ($booking->invoice?->total_amount ?? $booking->quotation?->total_amount ?? 0);
        $paidAmount = (float) $booking->payments->where('status', 'completed')->sum('amount');
        $outstandingBalance = max(0, $totalAmount - $paidAmount);
        $forceComplete = (bool) ($validated['force_complete'] ?? false);
        $reason = trim((string) ($validated['reason'] ?? ''));

        if ($outstandingBalance > 0.01 && ! $forceComplete) {
            return $this->fail('The event still has an unpaid balance. Pay the remaining balance or confirm completion as a debt booking event.', 422, [
                'requires_confirmation' => true,
                'outstanding_balance' => $outstandingBalance,
            ]);
        }

        if ($outstandingBalance > 0.01 && $reason === '') {
            return $this->fail('A reason is required to complete an event with an outstanding balance.', 422);
        }

        $eventService = app(EventService::class);
        $eventService->completeEvent(
            $booking,
            true,
            [
                'debt_booking_event' => $outstandingBalance > 0.01,
                'outstanding_balance' => $outstandingBalance,
                'override_reason' => $outstandingBalance > 0.01 ? $reason : null,
                'override_approved_by' => $outstandingBalance > 0.01 ? auth()->id() : null,
                'override_approved_at' => $outstandingBalance > 0.01 ? now()->toDateTimeString() : null,
                'approved_by' => auth()->id(),
                'approved_at' => now()->toDateTimeString(),
            ]
        );
        
        // ✅ Notification: Event completed
        $customer = $booking->serviceEvent?->customer;
        if ($customer && $customer->user_id) {
            $this->notificationService->notifyUser(
                $customer->user_id,
                'event_completed',
                $outstandingBalance > 0.01 ? 'Event Completed - Balance Due' : 'Event Completed',
                $outstandingBalance > 0.01
                    ? "Your event {$booking->booking_no} has been completed. An outstanding balance of ₱" . number_format($outstandingBalance, 2) . ' remains due.'
                    : "Your event {$booking->booking_no} has been completed successfully. Thank you for choosing us!",
                \App\Models\Notification::PRIORITY_HIGH,
                ['booking_id' => $booking->booking_id]
            );
        }
        
        return $this->ok([
            'event_completed' => true,
            'booking_completed' => true,
            'debt_booking_event' => $outstandingBalance > 0.01,
            'outstanding_balance' => $outstandingBalance,
        ], $outstandingBalance > 0.01
            ? 'Event completed and moved to history with an outstanding balance recorded.'
            : 'Event marked as completed');
    }

    public function getEquipment($id)
    {
        $booking = Booking::with('equipment.equipment')->findOrFail($id);
        $eventService = app(EventService::class);
        
        return $this->ok($eventService->getEventEquipment($booking));
    }

    public function approveAllEquipment(Request $request, $id)
    {
        $validated = $request->validate([
            'expected_return_date' => 'nullable|date',
            'condition_out' => 'nullable|string|max:500',
            'checked_out_by' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        $booking = Booking::with('equipment.equipment')->findOrFail($id);
        $items = $booking->equipment
            ->filter(fn ($item) => ! (bool) ($item->is_out_approved ?? false) && $item->status !== 'returned')
            ->values();

        $updated = $this->approveEquipmentRows($booking, $items, $validated);

        return $this->ok([
            'approved_count' => $updated,
            'equipment' => app(EventService::class)->getEventEquipment($booking->fresh(['equipment.equipment'])),
        ], $updated > 0 ? 'All reserved equipment approved successfully' : 'No unapproved equipment remained');
    }

    public function approveSelectedEquipment(Request $request, $id)
    {
        $validated = $request->validate([
            'equipment_item_ids' => 'required|array|min:1',
            'equipment_item_ids.*' => 'integer|distinct|exists:booking_equipment,booking_equipment_id',
            'expected_return_date' => 'nullable|date',
            'condition_out' => 'nullable|string|max:500',
            'checked_out_by' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        $booking = Booking::with('equipment.equipment')->findOrFail($id);
        $items = BookingEquipment::where('booking_id', $booking->booking_id)
            ->whereIn('booking_equipment_id', $validated['equipment_item_ids'])
            ->get();

        if ($items->count() !== count($validated['equipment_item_ids'])) {
            return $this->fail('One or more selected equipment items do not belong to this event.', 422);
        }

        $updated = $this->approveEquipmentRows($booking, $items, $validated);

        return $this->ok([
            'approved_count' => $updated,
            'equipment' => app(EventService::class)->getEventEquipment($booking->fresh(['equipment.equipment'])),
        ], $updated > 0 ? 'Selected equipment approved successfully' : 'Selected equipment was already approved');
    }

    private function approveEquipmentRows(Booking $booking, $items, array $validated): int
    {
        $checkedOutBy = $validated['checked_out_by'] ?? auth()->user()?->person?->full_name ?: auth()->user()?->username ?: 'Event Management';
        $expectedReturnDate = $validated['expected_return_date']
            ?? $booking->serviceEvent?->event_end_date?->toDateString()
            ?? now()->addDays(7)->toDateString();
        $conditionOut = $validated['condition_out'] ?? 'Good';
        $notes = $validated['notes'] ?? null;

        $itemIds = collect($items)->pluck('booking_equipment_id')->filter()->values();

        return DB::transaction(function () use ($itemIds, $checkedOutBy, $expectedReturnDate, $conditionOut, $notes) {
            $count = 0;
            $lockedItems = BookingEquipment::whereIn('booking_equipment_id', $itemIds)
                ->lockForUpdate()
                ->get();

            foreach ($lockedItems as $equipmentItem) {
                if ((bool) ($equipmentItem->is_out_approved ?? false) || $equipmentItem->status === 'returned') {
                    continue;
                }

                $updateData = [
                    'status' => 'checked_out',
                    'quantity_used' => (int) ($equipmentItem->quantity_reserved ?? 0),
                    'checked_out_date' => $equipmentItem->checked_out_date ?? now(),
                    'rental_end_date' => $expectedReturnDate,
                    'condition_notes_out' => $conditionOut,
                    'checked_out_by' => $checkedOutBy,
                    'is_out_approved' => true,
                    'out_approved_at' => now(),
                ];
                if ($notes && Schema::hasColumn('booking_equipment', 'notes')) {
                    $updateData['notes'] = $notes;
                }
                $equipmentItem->update($updateData);
                $count++;
            }
            return $count;
        });
    }

    public function checkoutEquipment(Request $request, $id)
    {
        $validated = $request->validate([
            'booking_equipment_id' => 'nullable|exists:booking_equipment,booking_equipment_id',
            'equipment_id' => 'required_without:booking_equipment_id|exists:equipment,equipment_id',
            'quantity' => 'required_without:booking_equipment_id|integer|min:1',
            'expected_return_date' => 'required|date|after_or_equal:today',
            'condition_out' => 'nullable|string',
            'checked_out_by' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);
        
        $booking = Booking::with('serviceEvent')->findOrFail($id);

        // Existing reservation approval: this is the normal Event Management workflow.
        if (! empty($validated['booking_equipment_id'])) {
            $equipmentItem = BookingEquipment::where('booking_id', $booking->booking_id)
                ->where('booking_equipment_id', $validated['booking_equipment_id'])
                ->firstOrFail();

            $updated = $this->approveEquipmentRows($booking, collect([$equipmentItem]), $validated);

            return $this->ok(
                $equipmentItem->fresh('equipment'),
                $updated > 0 ? 'Equipment checked out successfully' : 'Equipment was already approved'
            );
        }

        // New reservation workflow: validate stock through the existing equipment service first,
        // then approve the newly-created reservation. This keeps inventory calculations consistent.
        $startDate = $booking->serviceEvent?->event_date?->toDateString() ?? now()->toDateString();
        $equipmentItem = app(\App\Services\EquipmentService::class)->reserveEquipment(
            $booking,
            (int) $validated['equipment_id'],
            (int) $validated['quantity'],
            $startDate,
            $validated['expected_return_date']
        );

        $this->approveEquipmentRows($booking, collect([$equipmentItem]), $validated);
        $this->notificationService->equipmentReserved($equipmentItem->fresh(), $booking);

        return $this->ok($equipmentItem->fresh('equipment'), 'Equipment checked out successfully');
    }

    public function returnEquipment(Request $request, $id, $transactionId)
    {
        $validated = $request->validate([
            'condition_in' => 'nullable|string|max:500',
            'quantity_used' => 'nullable|integer|min:0',
            'quantity_damaged' => 'nullable|integer|min:0',
            'quantity_missing' => 'nullable|integer|min:0',
            'returned_by' => 'nullable|string|max:150',
            'notes' => 'nullable|string|max:1000',
        ]);

        $booking = Booking::findOrFail($id);
        $equipmentItem = BookingEquipment::where('booking_id', $booking->booking_id)
            ->where('booking_equipment_id', $transactionId)
            ->firstOrFail();

        if ($equipmentItem->status === 'returned') {
            return $this->ok($equipmentItem->fresh('equipment'), 'Equipment was already returned');
        }

        $used = (int) ($validated['quantity_used'] ?? $equipmentItem->quantity_reserved ?? 0);
        $damaged = (int) ($validated['quantity_damaged'] ?? 0);
        $missing = (int) ($validated['quantity_missing'] ?? 0);
        if ($used + $damaged + $missing > (int) $equipmentItem->quantity_reserved) {
            return $this->fail('Used, damaged, and missing quantities cannot exceed the reserved quantity.', 422);
        }

        DB::transaction(function () use ($equipmentItem, $validated, $used, $damaged, $missing) {
            $equipmentItem->update([
                'status' => 'returned',
                'checked_in_date' => now(),
                'condition_notes_in' => $validated['condition_in'] ?? null,
                'returned_by' => $validated['returned_by'] ?? null,
                'return_notes' => $validated['notes'] ?? null,
                'quantity_used' => $used,
                'quantity_damaged' => $damaged,
                'quantity_missing' => $missing,
            ]);

            if (($damaged > 0 || $missing > 0) && $equipmentItem->equipment) {
                $equipmentItem->equipment->update(['condition' => 'Fair']);
            }
        });
        
        // Equipment damage/missing counts are kept on booking_equipment rows.
        
        // ✅ Notifications for damaged/missing equipment
        if (($validated['quantity_damaged'] ?? 0) > 0) {
            $this->notificationService->equipmentDamaged($equipmentItem, $booking, $validated['quantity_damaged']);
        }
        
        if (($validated['quantity_missing'] ?? 0) > 0) {
            $this->notificationService->equipmentMissing($equipmentItem, $booking, $validated['quantity_missing']);
        }
        
        // Check if equipment is overdue
        if ($equipmentItem->rental_end_date < now()) {
            $this->notificationService->equipmentReturnOverdue($equipmentItem, $booking);
        }
        
        return $this->ok($equipmentItem, 'Equipment returned successfully');
    }

    public function getPendingDeductions($id, InventoryService $inventoryService)
    {
        $booking = Booking::with(['items.menuItem.recipeIngredients.ingredient.stock'])
            ->findOrFail($id);
        
        $deductions = $inventoryService->getPendingDeductions($booking);
        
        return $this->ok([
            'booking_id' => $booking->booking_id,
            'booking_no' => $booking->booking_no,
            'customer_name' => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
            'event_name' => $booking->serviceEvent?->eventType?->name ?? 'Event',
            'deductions' => $deductions,
        ]);
    }

    public function confirmDeductions(Request $request, $id, InventoryService $inventoryService)
    {
        $validated = $request->validate([
            'deductions' => 'nullable|array',
            'deductions.*.ingredient_id' => 'required|exists:ingredients,ingredient_id',
            'deductions.*.quantity' => 'required|numeric|min:0',
        ]);
        
        $booking = Booking::findOrFail($id);
        if ($booking->booking_status !== 'completed') {
            return $this->fail('Inventory deductions can only be confirmed after the booking is marked as completed.', 422);
        }
        
        $confirmedDeductions = [];
        foreach ($validated['deductions'] ?? [] as $deduction) {
            $confirmedDeductions[$deduction['ingredient_id']] = $deduction['quantity'];
        }
        
        $results = $inventoryService->deductForCompletedEvent($booking, $confirmedDeductions);
        
        // ✅ Notification: Ingredient computation completed
        if ($booking->order) {
            $this->notificationService->ingredientComputationCompleted($booking->order);
        }
        
        return $this->ok($results, 'Inventory deductions confirmed');
    }

    public function returnEventEquipment($eventCode)
    {
        $booking = Booking::where('booking_no', $eventCode)
            ->orWhere('booking_id', $eventCode)
            ->firstOrFail();
        
        $returnedCount = 0;
        foreach ($booking->equipment as $eq) {
            if ($eq->status !== 'returned') {
                $eq->update([
                    'status' => 'returned',
                    'checked_in_date' => now(),
                ]);
                $returnedCount++;
            }
        }
        
        return $this->ok(['returned' => $returnedCount], 'All equipment returned');
    }

    public function store(Request $request)
    {
        return $this->fail('Create events through a confirmed booking.', 422);
    }

    public function update(Request $request, $id)
    {
        $booking = Booking::with('serviceEvent')->findOrFail($id);
        if (! $booking->serviceEvent) {
            return $this->fail('This booking does not have an event record.', 422);
        }

        $validated = $request->validate([
            'event_date' => 'sometimes|required|date',
            'event_end_date' => 'nullable|date',
            'event_time' => 'sometimes|required|string|max:50',
            'venue' => 'sometimes|required|string|max:500',
            'guests_count' => 'sometimes|required|integer|min:1',
            'status' => 'sometimes|required|in:pending,confirmed,ongoing,completed,cancelled',
            'special_requests' => 'nullable|string|max:5000',
        ]);

        $startDate = Carbon::parse($validated['event_date'] ?? $booking->serviceEvent->event_date);
        if (! empty($validated['event_end_date']) && Carbon::parse($validated['event_end_date'])->lt($startDate)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'event_end_date' => 'The event end date must be on or after the event date.',
            ]);
        }

        DB::transaction(function () use ($booking, $validated) {
            $status = $validated['status'] ?? null;
            $eventData = $validated;
            unset($eventData['status']);
            if ($eventData !== []) {
                $booking->serviceEvent->update($eventData);
            }

            if ($status === 'completed') {
                app(EventService::class)->completeEvent($booking->fresh('serviceEvent'));
            } elseif ($status !== null) {
                $booking->update(['booking_status' => $status]);
                $booking->serviceEvent->update(['status' => $status]);
            }
        });

        return $this->ok($this->formatEvent($this->query()->findOrFail($id)), 'Event updated');
    }

    public function destroy($id)
    {
        $booking = Booking::with('serviceEvent')->findOrFail($id);
        DB::transaction(function () use ($booking) {
            $booking->update(['booking_status' => 'cancelled']);
            $booking->serviceEvent?->update(['status' => 'cancelled']);
        });
        return $this->ok(null, 'Event cancelled');
    }

    public function track(Request $request, $id)
    {
        $data = $request->validate([
            'stage'=>'required|in:preparation,ready,ongoing,completed',
            'progress_percentage'=>'nullable|integer|min:0|max:100',
            'notes'=>'nullable'
        ]);
        $tracking = EventTracking::updateOrCreate(
            ['booking_id'=>$id,'stage'=>$data['stage']],
            [
                'progress_percentage'=>$data['progress_percentage'] ?? 0,
                'notes'=>is_array($data['notes'] ?? null) ? json_encode($data['notes']) : ($data['notes'] ?? null),
                'stage_started_at'=>now()
            ]
        );
        return $this->ok($tracking, 'Event tracking updated');
    }

    public function liveStatus($id)
    {
        $booking = $this->query()->findOrFail($id);
        return $this->ok($this->formatEvent($booking)['live_status']);
    }

    public function updateLiveStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'current_phase'=>'nullable|in:pending,confirmed,preparation,ready,ongoing,completed,cancelled',
            'progress'=>'nullable|integer|min:0|max:100',
            'notes'=>'nullable|string|max:5000',
            'issues'=>'nullable|array',
            'current_day'=>'nullable|integer|min:1',
            'is_done'=>'nullable|boolean',
        ]);

        $booking = Booking::with('serviceEvent')->findOrFail($id);
        $status = app(EventService::class)->updateLiveStatus($booking, $validated);

        return $this->ok($status, 'Live status updated');
    }


    public function startEvent(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
            'force_start' => 'nullable|boolean',
        ]);

        $booking = Booking::with(['serviceEvent', 'payments', 'invoice', 'quotation'])->findOrFail($id);
        $eventDate = $booking->serviceEvent?->event_date;
        $scheduledDate = $eventDate ? Carbon::parse($eventDate)->toDateString() : null;
        $today = now()->toDateString();
        $isDifferentDate = $scheduledDate && $scheduledDate !== $today;
        $totalAmount = (float) ($booking->invoice?->total_amount ?? $booking->quotation?->total_amount ?? 0);
        $paidAmount = (float) $booking->payments->where('status', 'completed')->sum('amount');
        $requiredDeposit = round($totalAmount * 0.30, 2);
        $depositBalance = max(0, $requiredDeposit - $paidAmount);
        $depositOverride = $depositBalance > 0.01 && ! empty($validated['force_start']);

        if (($isDifferentDate || $depositBalance > 0.01) && empty($validated['force_start'])) {
            if ($depositBalance > 0.01) {
                return $this->fail('The required 30% deposit has not been paid. Pay the deposit or confirm a manual override.', 422, [
                    'required_deposit' => $requiredDeposit,
                    'paid_amount' => $paidAmount,
                    'deposit_balance' => $depositBalance,
                    'requires_confirmation' => true,
                ]);
            }
            return $this->fail('This event is scheduled for ' . Carbon::parse($eventDate)->format('F d, Y') . '. Confirm manual start first.', 422);
        }

        if (($isDifferentDate || $depositOverride) && empty(trim((string) ($validated['reason'] ?? '')))) {
            return $this->fail('Please enter a reason before manually starting this event.', 422);
        }

        DB::transaction(function () use ($booking, $validated, $isDifferentDate, $depositOverride, $requiredDeposit, $paidAmount, $depositBalance) {
            $booking->update(['booking_status' => 'ongoing']);
            if ($booking->serviceEvent) {
                $booking->serviceEvent->update(['status' => 'ongoing']);
            }

            $tracking = EventTracking::where('booking_id', $booking->booking_id)
                ->where('stage', 'ongoing')
                ->lockForUpdate()
                ->first();
            $metadata = $tracking ? json_decode((string) $tracking->notes, true) : [];
            $metadata = is_array($metadata) ? $metadata : [];
            $metadata = array_merge($metadata, [
                'started_manually' => $isDifferentDate || $depositOverride,
                'reason' => $validated['reason'] ?? null,
                'started_at' => now()->toDateTimeString(),
                'deposit_override' => $depositOverride,
                'required_deposit' => $requiredDeposit,
                'paid_at_start' => $paidAmount,
                'deposit_balance_at_start' => $depositBalance,
                'approved_by' => auth()->id(),
                'approved_at' => now()->toDateTimeString(),
            ]);

            EventTracking::updateOrCreate(
                ['booking_id' => $booking->booking_id, 'stage' => 'ongoing'],
                [
                    'progress_percentage' => max(10, (int) ($tracking?->progress_percentage ?? 0)),
                    'stage_started_at' => $tracking?->stage_started_at ?? now(),
                    'notes' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
        });

        return $this->ok($this->formatEvent($this->query()->findOrFail($id)), 'Event started.');
    }

    public function updateMealStatus(Request $request, $id, $mealServiceId)
    {
        $validated = $request->validate([
            'meal_status' => 'nullable|string|max:40',
            'preparation_status' => 'nullable|string|max:40',
            'delivery_status' => 'nullable|string|max:40',
            'serving_status' => 'nullable|string|max:40',
            'notes' => 'nullable|string',
        ]);

        $meal = MealService::where('booking_id', $id)->where('meal_service_id', $mealServiceId)->firstOrFail();
        $meal->update(array_filter($validated, fn($value) => $value !== null));

        return $this->ok($meal->fresh(), 'Meal service status updated.');
    }


    private function checklistDueAt($serviceDate, $servingTime): ?Carbon
    {
        if (! $serviceDate) {
            return null;
        }

        try {
            $date = Carbon::parse($serviceDate)->toDateString();
            $time = $servingTime ? Carbon::parse((string) $servingTime)->format('H:i:s') : '12:00:00';

            return Carbon::createFromFormat('Y-m-d H:i:s', "{$date} {$time}");
        } catch (\Throwable $exception) {
            Log::warning('Invalid meal checklist due date; using noon fallback', [
                'service_date' => (string) $serviceDate,
                'serving_time' => (string) $servingTime,
                'message' => $exception->getMessage(),
            ]);

            try {
                return Carbon::parse($serviceDate)->startOfDay()->addHours(12);
            } catch (\Throwable) {
                return null;
            }
        }
    }

    private function ensureChecklistRows(Booking $booking): array
    {
        $booking->loadMissing(['serviceEvent', 'mealServices.eventDay', 'eventChecklistItems', 'equipment.equipment', 'payments']);
        $defaults = [
            ['all_recipes_purchased', 'All ingredients/recipes purchased', 'Purchasing Team'],
            ['buffet_setup_completed', 'Buffet table setup completed', 'Operations Team'],
            ['utensils_arranged', 'Utensils arranged', 'Operations Team'],
            ['drinks_refilled', 'Drinks refilled', 'Service Crew'],
            ['staff_assigned', 'Staff assigned', 'Admin'],
            ['equipment_checked_out', 'Equipment checked out', 'Operations Team'],
            ['equipment_returned', 'Equipment returned', 'Operations Team'],
            ['dining_area_cleaned', 'Dining area cleaned', 'Service Crew'],
            ['payment_30_completed', '30% payment completed', 'Cashier'],
            ['food_delivered', 'Food delivered', 'Delivery Team'],
            ['breakfast_served', 'Breakfast served', 'Service Crew'],
            ['morning_snacks_served', 'Morning snacks served', 'Service Crew'],
            ['lunch_served', 'Lunch served', 'Service Crew'],
            ['afternoon_snacks_served', 'Afternoon snacks served', 'Service Crew'],
            ['dinner_served', 'Dinner served', 'Service Crew'],
        ];

        foreach ($defaults as [$key, $task, $assignee]) {
            $item = EventChecklistItem::firstOrCreate(
                ['booking_id' => $booking->booking_id, 'task_key' => $key],
                ['task' => $task, 'assigned_to' => $assignee, 'status' => 'pending', 'source_type' => 'system']
            );

            if ($item->source_type === 'system' && ($item->task !== $task || $item->assigned_to !== $assignee)) {
                $item->update(['task' => $task, 'assigned_to' => $assignee]);
            }
        }

        foreach ($booking->mealServices as $meal) {
            $taskKey = 'food_out_' . $meal->meal_service_id;
            $mealStatus = in_array($meal->meal_status, ['delivered', 'serving', 'served', 'completed'], true) ? 'completed' : 'pending';
            $mealValues = [
                'meal_service_id' => $meal->meal_service_id,
                'task' => 'Food already out: Day ' . $meal->day_number . ' - ' . $meal->meal_type . ' (' . ($meal->serving_time ?? '-') . ')',
                'assigned_to' => 'Kitchen / Delivery Team',
                'status' => $mealStatus,
                'source_type' => 'meal_service',
                'due_at' => $this->checklistDueAt($meal->service_date, $meal->serving_time),
            ];

            $mealItem = EventChecklistItem::firstOrCreate(
                ['booking_id' => $booking->booking_id, 'task_key' => $taskKey],
                $mealValues
            );

            if (! (bool) ($mealItem->manual_override ?? false)) {
                if (Schema::hasColumn('event_checklist_items', 'completed_at')) {
                    $mealValues['completed_at'] = $mealStatus === 'completed' ? now() : null;
                }
                $mealItem->update($mealValues);
            }
        }

        $rows = EventChecklistItem::with('mealService.eventDay')->where('booking_id', $booking->booking_id)->get();

        // Keep system-detected rows fresh without overwriting manual rows.
        $paid = (float) $booking->payments()->where('status', 'completed')->sum('amount');
        $allEquipmentOut = $booking->equipment->count() > 0 && $booking->equipment->every(fn ($eq) => in_array($eq->status, ['checked_out', 'returned'], true));
        $allEquipmentReturned = $booking->equipment->count() > 0 && $booking->equipment->every(fn ($eq) => $eq->status === 'returned');
        $allMealsDelivered = $booking->mealServices->count() > 0 && $booking->mealServices->every(fn ($meal) => in_array($meal->delivery_status, ['arrived', 'serving', 'completed'], true) || in_array($meal->meal_status, ['delivered', 'serving', 'served', 'completed'], true));

        $preparationTracking = $booking->tracking()->where('stage', 'preparation')->first();
        $preparationMetadata = json_decode($preparationTracking?->notes ?? '[]', true);
        $hasAssignedStaff = ! empty($preparationMetadata['assigned_staff'] ?? []);

        $detected = [
            'staff_assigned' => $hasAssignedStaff,
            'payment_30_completed' => $paid >= (float) ($booking->required_deposit ?? 0),
            'equipment_checked_out' => $allEquipmentOut,
            'equipment_returned' => $allEquipmentReturned,
            'food_delivered' => $allMealsDelivered,
        ];
        foreach ($detected as $taskKey => $isDone) {
            $query = EventChecklistItem::where('booking_id', $booking->booking_id)
                ->where('task_key', $taskKey)
                ->where('source_type', 'system');
            if (Schema::hasColumn('event_checklist_items', 'manual_override')) {
                $query->where('manual_override', false);
            }
            $updates = ['status' => $isDone ? 'completed' : 'pending'];
            if (Schema::hasColumn('event_checklist_items', 'completed_at')) {
                $updates['completed_at'] = $isDone ? now() : null;
            }
            $query->update($updates);
        }

        return $this->formatChecklistRows(EventChecklistItem::with('mealService.eventDay')->where('booking_id', $booking->booking_id)->orderBy('event_checklist_item_id')->get());
    }

    private function formatChecklistRows($rows): array
    {
        return collect($rows)->map(fn ($item) => [
            'id' => $item->event_checklist_item_id,
            'event_checklist_item_id' => $item->event_checklist_item_id,
            'meal_service_id' => $item->meal_service_id,
            'meal_type' => $item->mealService?->meal_type,
            'day_number' => $item->mealService?->day_number,
            'service_date' => $item->mealService?->service_date?->toDateString(),
            'task_key' => $item->task_key,
            'task' => $item->task,
            'assigned_to' => $item->assigned_to,
            'status' => $item->status,
            'completed' => $item->status === 'completed',
            'source_type' => $item->source_type,
            'manual_override' => (bool) ($item->manual_override ?? false),
            'completed_at' => $item->completed_at?->toDateTimeString(),
            'notes' => $item->notes,
        ])->values()->all();
    }

    private function eventWarnings(Booking $booking): array
    {
        $warnings = [];
        if ($booking->mealServices->isEmpty()) {
            $warnings[] = ['type' => 'meals', 'severity' => 'critical', 'message' => 'No meal services found for this event.'];
        }
        if ($booking->serviceEvent?->service_type === 'buffet' && $booking->equipment->isEmpty()) {
            $warnings[] = ['type' => 'equipment', 'severity' => 'warning', 'message' => 'Buffet event has no equipment assigned yet.'];
        }
        return $warnings;
    }

    private function ensureDeliveryRows(Booking $booking): array
    {
        $booking->loadMissing(['serviceEvent', 'mealServices.eventDay', 'deliveryTrackings.mealService.eventDay']);
        foreach ($booking->mealServices as $meal) {
            EventDeliveryTracking::firstOrCreate(
                ['booking_id' => $booking->booking_id, 'meal_service_id' => $meal->meal_service_id],
                [
                    'delivery_type' => $booking->serviceEvent?->service_type ?? 'food',
                    'delivery_date' => $meal->service_date?->toDateString() ?? $booking->serviceEvent?->event_date?->toDateString(),
                    'delivery_time' => $meal->dispatch_time,
                    'return_time' => null,
                    'venue' => $booking->serviceEvent?->venue,
                    'driver' => null,
                    'status' => $meal->delivery_status === 'pending' ? 'pending' : $meal->delivery_status,
                    'notes' => $meal->notes,
                ]
            );
        }
        return $this->formatDeliveryRows(EventDeliveryTracking::with('mealService.eventDay')->where('booking_id', $booking->booking_id)->orderBy('delivery_date')->orderBy('delivery_time')->get());
    }

    private function formatDeliveryRows($rows): array
    {
        return collect($rows)->map(function ($delivery) {
            $metadata = $this->deliveryMetadata($delivery->notes);
            return [
            'id' => $delivery->event_delivery_tracking_id,
            'event_delivery_tracking_id' => $delivery->event_delivery_tracking_id,
            'meal_service_id' => $delivery->meal_service_id,
            'day_number' => $delivery->mealService?->day_number,
            'meal_type' => $delivery->mealService?->meal_type,
            'event_date' => $delivery->delivery_date?->toDateString(),
            'delivery_date' => $delivery->delivery_date?->toDateString(),
            'delivery_time' => $delivery->delivery_time,
            'return_time' => $delivery->return_time,
            'venue' => $delivery->venue,
            'driver' => $delivery->driver,
            'driver_phone' => $delivery->driver_phone,
            'delivery_type' => $delivery->delivery_type,
            'status' => $delivery->status,
            'vehicle' => $metadata['vehicle'] ?? null,
            'items' => $metadata['items'] ?? null,
            'notes' => $metadata['notes'] ?? null,
        ];
        })->values()->all();
    }

    private function deliveryMetadata(?string $notes): array
    {
        if (! $notes) return [];
        $decoded = json_decode($notes, true);
        if (is_array($decoded)) return $decoded;
        $lines = preg_split('/\R/', $notes, 2) ?: [];
        return [
            'vehicle' => trim((string) ($lines[0] ?? '')) ?: null,
            'items' => null,
            'notes' => trim((string) ($lines[1] ?? '')) ?: null,
        ];
    }

    private function formatEvent(Booking $booking): array
    {
        $event = $booking->serviceEvent;
        $person = $event?->customer?->person;
        $customerName = $person?->full_name ?? 'Unknown';
        $eventName = $event?->eventType?->name ?? 'Event';
        
        $trackingRows = $booking->relationLoaded('tracking') ? $booking->tracking : collect();
        $mealRows = $booking->relationLoaded('mealServices') ? $booking->mealServices : collect();
        $eventDayRows = $booking->relationLoaded('eventDays') ? $booking->eventDays : collect();
        $equipmentRows = $booking->relationLoaded('equipment') ? $booking->equipment : collect();
        $checklistRows = $booking->relationLoaded('eventChecklistItems') ? $booking->eventChecklistItems : collect();
        $deliveryRows = $booking->relationLoaded('deliveryTrackings') ? $booking->deliveryTrackings : collect();
        $paymentRows = $booking->relationLoaded('payments') ? $booking->payments : collect();
        $tracking = $trackingRows->where('stage', 'ongoing')->first();
        $progressData = json_decode($tracking?->notes ?? '[]', true);
        $preparationTracking = $trackingRows->where('stage', 'preparation')->first();
        $preparationMetadata = json_decode($preparationTracking?->notes ?? '[]', true);
        $assignedStaff = collect($preparationMetadata['assigned_staff'] ?? [])->values();
        $completedTracking = $trackingRows->where('stage', 'completed')->first();
        $completionMetadata = json_decode($completedTracking?->notes ?? '[]', true);
        $completionMetadata = is_array($completionMetadata) ? $completionMetadata : [];
        $mealServices = $mealRows->map(fn($meal) => [
            'id' => $meal->meal_service_id,
            'meal_service_id' => $meal->meal_service_id,
            'event_day_id' => $meal->event_day_id,
            'day_number' => (int) $meal->day_number,
            'date' => $meal->service_date?->toDateString(),
            'service_date' => $meal->service_date?->toDateString(),
            'meal_type' => $meal->meal_type,
            'serving_time' => $meal->serving_time,
            'preparation_time' => $meal->preparation_time,
            'dispatch_time' => $meal->dispatch_time,
            'arrival_time' => $meal->arrival_time,
            'pax' => (int) $meal->pax,
            'menu_source' => $meal->menu_source,
            'package_id' => $meal->package_id,
            'menu_item_id' => $meal->menu_item_id,
            'menu_name' => $meal->menu_name,
            'menu_description' => $meal->menu_description,
            'price_per_head' => (float) $meal->price_per_head,
            'total_meal_amount' => (float) $meal->total_meal_amount,
            'filters' => ($meal->relationLoaded('filters') ? $meal->filters : collect())->map(fn ($filter) => [
                'filter_key' => $filter->filter_key,
                'filter_value' => $filter->filter_value,
            ])->values(),
            'custom_items' => ($meal->relationLoaded('customItems') ? $meal->customItems : collect())->map(fn ($item) => [
                'meal_service_custom_item_id' => $item->meal_service_custom_item_id,
                'menu_item_id' => $item->menu_item_id,
                'item_name' => $item->item_name ?? $item->menuItem?->name,
                'description' => $item->description,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'notes' => $item->notes,
            ])->values(),
            'notes' => $meal->notes,
            'preparation_status' => $meal->preparation_status,
            'delivery_status' => $meal->delivery_status,
            'serving_status' => $meal->serving_status,
            'meal_status' => $meal->meal_status,
        ])->values();
        $maxMealDay = max(1, (int)($eventDayRows->max('day_number') ?? 1));
        $computedTotalDays = $event?->event_date && $event?->event_end_date
            ? max(1, Carbon::parse($event->event_date)->diffInDays(Carbon::parse($event->event_end_date)) + 1)
            : $maxMealDay;
        $timeline = $mealServices->map(fn($meal) => [
            'time' => $meal['serving_time'],
            'activity' => 'Serve ' . $meal['meal_type'] . ($meal['menu_name'] ? ' - ' . $meal['menu_name'] : ''),
            'status' => $meal['meal_status'] ?? 'pending',
            'date' => $meal['date'],
        ])->values();
        
        return [
            'id' => $booking->booking_id,
            'event_id' => $booking->booking_id,
            'event_name' => $eventName,
            'display_name' => "{$customerName} - {$eventName}",
            'customer_name' => $customerName,
            'event_type' => $eventName,
            'booking_no' => $booking->booking_no,
            'customer_email' => $person?->email,
            'customer_phone' => $person?->phone,
            'date' => $event?->event_date?->toDateString(),
            'end_date' => $event?->event_end_date?->toDateString(),
            'time' => $event?->event_time,
            'location' => $event?->venue,
            'venue' => $event?->venue,
            'guests_count' => $event?->guests_count ?? 0,
            'status' => $booking->booking_status,
            'order_status' => $booking->order?->status,
            'total_amount' => $booking->invoice?->total_amount ?? $booking->quotation?->total_amount ?? 0,
            'paid_amount' => $booking->invoice?->paid_amount ?? $paymentRows->where('status', 'completed')->sum('amount') ?? 0,
            'balance' => max(0, ($booking->invoice?->total_amount ?? $booking->quotation?->total_amount ?? 0) - ($paymentRows->where('status', 'completed')->sum('amount') ?? 0)),
            'progress' => $tracking?->progress_percentage ?? 0,
            'is_multi_day' => ($event?->booking_scope === 'multi_day') || $computedTotalDays > 1,
            'total_days' => $computedTotalDays,
            'current_day' => $progressData['current_day'] ?? 1,
            'assigned_staff' => $assignedStaff,
            'total_staff_required' => $assignedStaff->count(),
            'event_completed' => (bool) ($completionMetadata['event_completed'] ?? false),
            'debt_booking_event' => (bool) ($completionMetadata['debt_booking_event'] ?? false),
            'was_debt_booking_event' => (bool) ($completionMetadata['was_debt_booking_event'] ?? $completionMetadata['debt_booking_event'] ?? false),
            'completion_override_reason' => $completionMetadata['override_reason'] ?? null,
            'outstanding_balance' => max(0, ($booking->invoice?->total_amount ?? $booking->quotation?->total_amount ?? 0) - ($paymentRows->where('status', 'completed')->sum('amount') ?? 0)),
            'equipment_in_out' => $equipmentRows->map(fn($eq) => [
                'id' => $eq->booking_equipment_id,
                'equipment_name' => $eq->equipment?->name,
                'equipment_status' => $eq->status,
                'quantity' => $eq->quantity_reserved,
                'unit' => 'units',
                'checkout_date' => $eq->checked_out_date?->toDateString(),
                'expected_return_date' => $eq->rental_end_date?->toDateString(),
                'actual_return_date' => $eq->checked_in_date?->toDateString(),
                'status' => $eq->status,
                'check_out_status' => ($eq->is_out_approved ?? false) ? 'approved' : 'pending_approval',
                'check_in_status' => $eq->checked_in_date ? 'returned' : 'not_returned',
                'condition_out' => $eq->condition_notes_out,
                'condition_in' => $eq->condition_notes_in,
                'is_out_approved' => (bool) ($eq->is_out_approved ?? false),
                'checked_out_by' => $eq->checked_out_by ?? null,
                'returned_by' => $eq->returned_by ?? null,
                'return_notes' => $eq->return_notes ?? null,
                'quantity_used' => (int) ($eq->quantity_used ?? 0),
                'quantity_damaged' => (int) ($eq->quantity_damaged ?? 0),
                'quantity_missing' => (int) ($eq->quantity_missing ?? 0),
            ]),
            'live_status' => [
                'current_phase' => $booking->booking_status,
                'progress' => $tracking?->progress_percentage ?? 0,
                'next_milestone' => $booking->booking_status === 'confirmed' ? 'Event preparation' : 'Event completion',
                'issues' => [],
                'notes' => '',
            ],
            'checklist' => $this->formatChecklistRows($checklistRows),
            'delivery_tracking' => $this->formatDeliveryRows($deliveryRows),
            'event_days' => $eventDayRows->map(fn($day) => [
                'day_number' => (int) $day->day_number,
                'date' => $day->date?->toDateString(),
                'day_status' => $day->day_status,
                'day_total_amount' => (float) $day->day_total_amount,
            ])->values(),
            'meal_services' => $mealServices,
            'meal_schedule' => $mealServices,
            'timeline' => $timeline,
        ];
    }
}