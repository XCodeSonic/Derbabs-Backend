<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Delivery;
use App\Models\Employee;
use App\Models\EventTracking;
use App\Models\Schedule;
use App\Models\ServiceEvent;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EventService
{
    /**
     * Generate sequential delivery number (DEL-0013 format)
     */
    private function generateDeliveryNumber(): string
    {
        return $this->generateSequentialNumber('DEL-', Delivery::class, 'delivery_number');
    }

    /**
     * Generic sequential number generator
     */
    private function generateSequentialNumber(string $prefix, string $modelClass, string $column, int $padding = 4): string
    {
        try {
            if (!class_exists($modelClass)) {
                throw new \Exception("Model class {$modelClass} not found");
            }

            $instance = new $modelClass();
            $keyName = $instance->getKeyName();

            $lastRecord = $modelClass::withTrashed()
                ->where($column, 'LIKE', $prefix . '%')
                ->orderBy($keyName, 'desc')
                ->first();

            if ($lastRecord && isset($lastRecord->$column)) {
                $lastNumber = intval(substr($lastRecord->$column, strlen($prefix)));
                $newNumber = str_pad($lastNumber + 1, $padding, '0', STR_PAD_LEFT);
            } else {
                $newNumber = str_repeat('0', $padding - 1) . '1';
            }

            return $prefix . $newNumber;
        } catch (\Exception $e) {
            Log::warning("Failed to generate sequential number for {$prefix}: " . $e->getMessage());
            return $prefix . now()->format('YmdHisv') . '-' . strtoupper(substr(uniqid(), -4));
        }
    }

    /**
     * Assign staff to event with automatic schedule creation
     */
    public function assignStaffToEvent(Booking $booking, array $assignments): array
    {
        $metadata = $this->mutateMetadata($booking, 'preparation', function (array $metadata) use ($assignments): array {
            $existing = $this->normalizeAssignedStaffRows($metadata['assigned_staff'] ?? [])
                ->keyBy(fn(array $row) => (int) ($row['staff_id'] ?? 0));

            foreach ($assignments as $assignment) {
                $staffId = (int) ($assignment['staff_id'] ?? 0);
                if ($staffId <= 0) {
                    continue;
                }

                $existing[$staffId] = array_merge($existing[$staffId] ?? [], [
                    'staff_id' => $staffId,
                    'role' => (string) ($assignment['role'] ?? 'Staff'),
                    'schedule' => (string) ($assignment['schedule'] ?? '08:00 - 17:00'),
                    'start_time' => $assignment['start_time'] ?? null,
                    'end_time' => $assignment['end_time'] ?? null,
                    'phone' => $assignment['phone'] ?? null,
                    'email' => $assignment['email'] ?? null,
                    'status' => $assignment['status'] ?? 'confirmed',
                    'assigned_at' => now()->toDateTimeString(),
                ]);
            }

            $metadata['assigned_staff'] = $existing->values()->all();
            return $metadata;
        });

        // Create actual Schedule records for each staff member
        $this->createStaffSchedules($booking, $assignments);

        return $this->hydrateAssignedStaff($metadata['assigned_staff'] ?? []);
    }

    /**
     * Create schedule records for assigned staff
     */
    private function createStaffSchedules(Booking $booking, array $assignments): void
    {
        $event = $booking->serviceEvent;
        if (!$event || !$event->event_date) {
            Log::warning('Cannot create staff schedules: No event date found', [
                'booking_id' => $booking->booking_id
            ]);
            return;
        }

        $startDate = Carbon::parse($event->event_date);
        $endDate = $event->event_end_date
            ? Carbon::parse($event->event_end_date)
            : $startDate->copy();

        foreach ($assignments as $assignment) {
            $staffId = (int) ($assignment['staff_id'] ?? 0);
            if ($staffId <= 0) {
                continue;
            }

            $startTime = $assignment['start_time'] ?? '08:00';
            $endTime = $assignment['end_time'] ?? '17:00';
            $role = $assignment['role'] ?? 'Staff';

            for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
                $this->createOrUpdateSchedule($booking, $staffId, $date, $startTime, $endTime, $role, $event);
            }
        }
    }

    /**
     * Create or update a single schedule record
     */
    private function createOrUpdateSchedule(Booking $booking, int $staffId, Carbon $date, string $startTime, string $endTime, string $role, ServiceEvent $event): void
    {
        // Resolve shift type
        $shiftType = $this->resolveShiftType($startTime, $endTime);

        // Check if schedule already exists
        $existing = Schedule::where('employee_id', $staffId)
            ->whereDate('work_date', $date->toDateString())
            ->first();

        $assignmentDetails = json_encode([
            'placement' => $event->venue ?? 'Event Venue',
            'notes' => "Assigned to event: {$booking->booking_no} - Role: {$role}",
            'booking_id' => $booking->booking_id,
            'role' => $role,
            'event_date' => $event->event_date->toDateString(),
        ]);

        $scheduleData = [
            'employee_id' => $staffId,
            'shift_type_id' => $shiftType->shift_type_id,
            'work_date' => $date->toDateString(),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'break_minutes' => (float) ($shiftType->break_minutes ?? 60),
            'assignment_details' => $assignmentDetails,
            'status' => 'scheduled',
        ];

        if (Schema::hasColumn('schedules', 'booking_id')) {
            $scheduleData['booking_id'] = $booking->booking_id;
        }

        if ($existing) {
            // Restore if soft deleted
            if ($existing->trashed()) {
                $existing->restore();
            }
            // Update existing schedule
            $existing->update($scheduleData);
            Log::info('Schedule updated for staff', [
                'staff_id' => $staffId,
                'date' => $date->toDateString(),
                'booking_id' => $booking->booking_id
            ]);
        } else {
            // Create new schedule
            Schedule::create($scheduleData);
            Log::info('Schedule created for staff', [
                'staff_id' => $staffId,
                'date' => $date->toDateString(),
                'booking_id' => $booking->booking_id
            ]);
        }
    }

    /**
     * Resolve or create shift type
     */
    private function resolveShiftType(string $startTime, string $endTime): ShiftType
    {
        // Try to find existing shift type with matching times
        $shiftType = ShiftType::where('default_start_time', $startTime)
            ->where('default_end_time', $endTime)
            ->where('is_active', true)
            ->first();

        if ($shiftType) {
            return $shiftType;
        }

        // Create a new shift type for this schedule
        $slug = 'shift-' . Str::slug($startTime . '-' . $endTime);
        $name = ucfirst($startTime) . ' - ' . ucfirst($endTime);

        return ShiftType::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'default_start_time' => $startTime,
                'default_end_time' => $endTime,
                'break_minutes' => 60,
                'night_differential_rate' => 0,
                'is_active' => true,
            ]
        );
    }

    /**
     * Remove staff from event and delete their schedules
     */
    public function removeStaffFromEvent(Booking $booking, int $staffId): void
    {
        // Remove from metadata
        $this->mutateMetadata($booking, 'preparation', function (array $metadata) use ($staffId): array {
            $metadata['assigned_staff'] = array_values(array_filter(
                $metadata['assigned_staff'] ?? [],
                fn($staff) => (int) ($staff['staff_id'] ?? 0) !== $staffId
            ));
            return $metadata;
        });

        // Delete schedules for this staff and booking
        $event = $booking->serviceEvent;
        if ($event && $event->event_date) {
            $startDate = Carbon::parse($event->event_date);
            $endDate = $event->event_end_date
                ? Carbon::parse($event->event_end_date)
                : $startDate->copy();

            for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
                $schedule = Schedule::where('employee_id', $staffId)
                    ->whereDate('work_date', $date->toDateString())
                    ->first();

                if ($schedule) {
                    // Check if this schedule is for this booking
                    $details = json_decode($schedule->assignment_details ?? '{}', true);
                    if (($details['booking_id'] ?? null) == $booking->booking_id) {
                        $schedule->delete();
                        Log::info('Schedule deleted for staff removal', [
                            'staff_id' => $staffId,
                            'date' => $date->toDateString(),
                            'booking_id' => $booking->booking_id
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Update staff status (confirmed/declined)
     */
    public function updateStaffStatus(Booking $booking, int $staffId, string $status): array
    {
        $metadata = $this->mutateMetadata($booking, 'preparation', function (array $metadata) use ($staffId, $status): array {
            $staff = &$metadata['assigned_staff'];
            if (is_array($staff)) {
                foreach ($staff as &$member) {
                    if ((int) ($member['staff_id'] ?? 0) === $staffId) {
                        $member['status'] = $status;
                        break;
                    }
                }
            }
            return $metadata;
        });

        return $this->hydrateAssignedStaff($metadata['assigned_staff'] ?? []);
    }

    /**
     * Get assigned staff with employee details
     */
    public function getAssignedStaff(Booking $booking): array
    {
        $metadata = $this->metadata($booking, 'preparation');
        return $this->hydrateAssignedStaff($metadata['assigned_staff'] ?? []);
    }

    /**
     * Hydrate assigned staff with employee information
     */
    private function hydrateAssignedStaff(array $assignedStaff): array
    {
        $rows = $this->normalizeAssignedStaffRows($assignedStaff);
        $staffIds = $rows
            ->pluck('staff_id')
            ->filter(fn($id) => (int) $id > 0)
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        $employees = Employee::with(['person', 'position', 'department'])
            ->whereIn('employee_id', $staffIds)
            ->get()
            ->keyBy('employee_id');

        return $rows->map(function (array $row) use ($employees) {
            $staffId = (int) ($row['staff_id'] ?? 0);
            $employee = $employees->get($staffId);
            $person = $employee?->person;
            $name = $employee?->full_name
                ?? $person?->full_name
                ?? trim(($person?->first_name ?? '') . ' ' . ($person?->last_name ?? ''))
                ?: 'Staff #' . $staffId;

            return [
                'staff_id' => $staffId,
                'employee_id' => $staffId,
                'name' => $name,
                'full_name' => $name,
                'role' => $row['role'] ?? 'Staff',
                'schedule' => $row['schedule'] ?? '08:00 - 17:00',
                'start_time' => $row['start_time'] ?? null,
                'end_time' => $row['end_time'] ?? null,
                'status' => $row['status'] ?? 'confirmed',
                'phone' => $row['phone'] ?? $person?->phone,
                'email' => $row['email'] ?? $person?->email,
                'position' => $employee?->position?->title ?? 'Staff',
                'department' => $employee?->department?->name ?? 'Operations',
                'assigned_at' => $row['assigned_at'] ?? null,
            ];
        })->filter(fn(array $row) => $row['staff_id'] > 0)->values()->all();
    }

    /**
     * Normalize assigned staff rows
     */
    private function normalizeAssignedStaffRows($assignedStaff): Collection
    {
        return collect(is_array($assignedStaff) ? $assignedStaff : [])
            ->map(function ($row) {
                if (is_object($row)) {
                    $row = (array) $row;
                } elseif (is_numeric($row)) {
                    $row = ['staff_id' => (int) $row];
                }

                return is_array($row) ? $row : null;
            })
            ->filter(fn($row) => is_array($row));
    }

    /**
     * Get available staff for assignment
     */
    public function getAvailableStaff(): array
    {
        return Employee::with(['person', 'position', 'department'])
            ->where('status', 'active')
            ->get()
            ->map(fn(Employee $employee) => [
                'employee_id' => $employee->employee_id,
                'name' => $employee->full_name,
                'position' => $employee->position?->title ?? 'Staff',
                'department' => $employee->department?->name ?? 'Operations',
                'phone' => $employee->person?->phone,
                'email' => $employee->person?->email,
                'hourly_rate' => (float) $employee->hourly_rate,
            ])->values()->all();
    }

    /**
     * Add delivery to event
     */
    public function addDelivery(Booking $booking, array $data): Delivery
    {
        $event = $booking->serviceEvent;
        if (!$event) {
            throw ValidationException::withMessages(['event' => 'Service event not found.']);
        }

        $scheduled = $data['scheduled_delivery']
            ?? $data['eta']
            ?? $event->scheduled_delivery_time
            ?? Carbon::parse($event->event_date?->toDateString() . ' ' . ($event->event_time ?: '09:00'));

        $driverId = is_numeric($data['driver_id'] ?? null) ? (int) $data['driver_id'] : null;
        if ($driverId && !User::where('user_id', $driverId)->exists()) {
            $driverId = null;
        }

        return Delivery::create([
            'delivery_number' => $this->generateDeliveryNumber(),
            'service_event_id' => $event->service_event_id,
            'driver_id' => $driverId,
            'scheduled_pickup' => $data['scheduled_pickup'] ?? null,
            'scheduled_delivery' => Carbon::parse($scheduled),
            'vehicle_type' => $data['vehicle_type'] ?? $data['vehicle'] ?? null,
            'vehicle_plate' => $data['vehicle_plate'] ?? null,
            'destination_address' => $data['destination_address'] ?? $event->delivery_address ?? $event->venue,
            'status' => $this->deliveryStatus($data['status'] ?? 'pending'),
            'notes' => json_encode([
                'driver_name' => $data['driver'] ?? null,
                'driver_phone' => $data['driver_phone'] ?? null,
                'location' => $data['location'] ?? null,
                'notes' => $data['notes'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * Update delivery status
     */
    public function updateDeliveryStatus(Booking $booking, int $deliveryId, string $status, ?string $location = null): Delivery
    {
        $delivery = Delivery::where('service_event_id', $booking->serviceEvent?->service_event_id)
            ->where('delivery_id', $deliveryId)
            ->firstOrFail();
        $notes = json_decode((string) $delivery->notes, true);
        $notes = is_array($notes) ? $notes : [];
        if ($location !== null) {
            $notes['location'] = $location;
        }

        $databaseStatus = $this->deliveryStatus($status);
        $updates = ['status' => $databaseStatus, 'notes' => json_encode($notes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        if ($databaseStatus === 'picked_up') {
            $updates['actual_pickup'] = now();
        }
        if ($databaseStatus === 'delivered') {
            $updates['actual_delivery'] = now();
        }
        $delivery->update($updates);

        return $delivery->fresh();
    }

    /**
     * Get deliveries for event
     */
    public function getDeliveries(Booking $booking): array
    {
        $booking->loadMissing(['serviceEvent.customer.person', 'serviceEvent.eventType']);
        $customer = $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown';
        $eventName = $booking->serviceEvent?->eventType?->name ?? 'Event';

        $deliveries = $booking->serviceEvent
            ? Delivery::where('service_event_id', $booking->serviceEvent->service_event_id)->latest('delivery_id')->get()->map(fn($delivery) => $this->formatDelivery($delivery))->values()->all()
            : [];

        return ['display_name' => "{$customer} - {$eventName}", 'deliveries' => $deliveries];
    }

    /**
     * Format delivery for response
     */
    private function formatDelivery(Delivery $delivery): array
    {
        $notes = json_decode((string) $delivery->notes, true);
        $notes = is_array($notes) ? $notes : [];

        return [
            'id' => $delivery->delivery_id,
            'delivery_id' => $delivery->delivery_id,
            'delivery_number' => $delivery->delivery_number,
            'vehicle' => $delivery->vehicle_type,
            'vehicle_plate' => $delivery->vehicle_plate,
            'driver' => $notes['driver_name'] ?? $delivery->driver?->person?->full_name,
            'driver_phone' => $notes['driver_phone'] ?? null,
            'eta' => $delivery->scheduled_delivery?->toDateTimeString(),
            'location' => $notes['location'] ?? null,
            'address' => $delivery->destination_address,
            'status' => $delivery->status,
            'actual_pickup' => $delivery->actual_pickup?->toDateTimeString(),
            'actual_delivery' => $delivery->actual_delivery?->toDateTimeString(),
        ];
    }

    /**
     * Get multi-day event progress
     */
    public function getMultiDayProgress(Booking $booking): array
    {
        $event = $booking->serviceEvent;
        $customer = $event?->customer?->person?->full_name ?? 'Unknown';
        $eventName = $event?->eventType?->name ?? 'Event';

        if (!$event?->event_date) {
            return ['days' => [], 'current_day' => 1, 'total_days' => 1, 'display_name' => "{$customer} - {$eventName}"];
        }

        $start = Carbon::parse($event->event_date);
        $end = Carbon::parse($event->event_end_date ?? $event->event_date);
        $totalDays = $start->diffInDays($end) + 1;
        $metadata = $this->mutateMetadata($booking, 'ongoing', function (array $metadata) use ($start, $totalDays, $event): array {
            $metadata['total_days'] = $totalDays;
            $metadata['current_day'] = min(max(1, (int) ($metadata['current_day'] ?? 1)), $totalDays);
            $metadata['is_multi_day'] = $totalDays > 1;
            $metadata['daily_progress'] ??= [];

            for ($day = 1; $day <= $totalDays; $day++) {
                $key = (string) $day;
                $existing = $metadata['daily_progress'][$key] ?? [];
                $metadata['daily_progress'][$key] = array_merge([
                    'completion' => 0,
                    'notes' => '',
                    'attendance' => 0,
                    'menu_items' => [],
                    'sessions' => [],
                ], $existing, [
                    'day' => $day,
                    'date' => $start->copy()->addDays($day - 1)->toDateString(),
                    'registered' => (int) $event->guests_count,
                ]);
            }

            return $metadata;
        });

        $days = [];
        for ($day = 1; $day <= $totalDays; $day++) {
            $row = $metadata['daily_progress'][(string) $day];
            $date = Carbon::parse($row['date']);
            $days[] = array_merge($row, [
                'day' => $day,
                'is_today' => $date->isToday(),
                'is_past' => $date->isPast(),
                'is_future' => $date->isFuture(),
            ]);
        }

        return [
            'days' => $days,
            'current_day' => (int) $metadata['current_day'],
            'total_days' => $totalDays,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'overall_progress' => round((float) collect($days)->avg('completion'), 2),
            'is_multi_day' => $totalDays > 1,
            'display_name' => "{$customer} - {$eventName}",
            'customer_name' => $customer,
            'event_name' => $eventName,
        ];
    }

    /**
     * Update daily progress
     */
    public function updateDailyProgress(Booking $booking, int $day, array $data): array
    {
        $progress = $this->getMultiDayProgress($booking);
        if ($day < 1 || $day > $progress['total_days']) {
            throw ValidationException::withMessages(['day' => 'Invalid event day.']);
        }

        $metadata = $this->mutateMetadata($booking, 'ongoing', function (array $metadata) use ($day, $data): array {
            $key = (string) $day;
            $metadata['daily_progress'][$key] = array_merge($metadata['daily_progress'][$key] ?? [], [
                'completion' => (int) ($data['completion'] ?? 0),
                'notes' => $data['notes'] ?? ($metadata['daily_progress'][$key]['notes'] ?? ''),
                'attendance' => (int) ($data['attendance'] ?? ($metadata['daily_progress'][$key]['attendance'] ?? 0)),
                'updated_at' => now()->toIso8601String(),
            ]);
            $metadata['current_day'] = $day;
            return $metadata;
        });

        $tracking = $this->tracking($booking, 'ongoing');
        $tracking->update([
            'progress_percentage' => round((float) collect($metadata['daily_progress'] ?? [])->avg('completion'), 2),
        ]);

        return $this->getMultiDayProgress($booking);
    }

    /**
     * Update attendance for a day
     */
    public function updateAttendance(Booking $booking, int $day, int $present): array
    {
        $progress = $this->getMultiDayProgress($booking);
        if ($day < 1 || $day > $progress['total_days']) {
            throw ValidationException::withMessages(['day' => 'Invalid event day.']);
        }

        return $this->mutateMetadata($booking, 'ongoing', function (array $metadata) use ($booking, $day, $present): array {
            $key = (string) $day;
            $registered = max(1, (int) ($metadata['daily_progress'][$key]['registered'] ?? $booking->serviceEvent?->guests_count ?? 1));
            $metadata['daily_progress'][$key] = array_merge($metadata['daily_progress'][$key] ?? [], [
                'attendance' => $present,
                'attendance_rate' => round(($present / $registered) * 100, 2),
                'updated_at' => now()->toIso8601String(),
            ]);
            return $metadata;
        });
    }

    /**
     * Advance to next day
     */
    public function advanceToNextDay(Booking $booking): array
    {
        $progress = $this->getMultiDayProgress($booking);
        if ($progress['current_day'] >= $progress['total_days']) {
            return ['success' => false, 'message' => 'Event is already on the last day.'];
        }

        $metadata = $this->mutateMetadata($booking, 'ongoing', function (array $metadata): array {
            $metadata['current_day'] = (int) ($metadata['current_day'] ?? 1) + 1;
            return $metadata;
        });

        return [
            'success' => true,
            'previous_day' => $progress['current_day'],
            'current_day' => (int) $metadata['current_day'],
            'total_days' => $progress['total_days'],
        ];
    }

    /**
     * Complete event
     */
    public function completeEvent(Booking $booking, bool $markBookingCompleted = true, array $completionMetadata = []): void
    {
        DB::transaction(function () use ($booking, $markBookingCompleted, $completionMetadata) {
            $wasAlreadyOperationallyCompleted = $booking->serviceEvent?->status === 'completed';

            if ($markBookingCompleted) {
                $booking->update(['booking_status' => 'completed']);
            }
            $booking->serviceEvent?->update(['status' => 'completed']);
            $booking->order?->update(['status' => 'completed']);

            if (! $wasAlreadyOperationallyCompleted) {
                app(InventoryService::class)->deductForCompletedEvent(
                    $booking->fresh(['serviceEvent', 'items.menuItem.recipeIngredients.ingredient', 'items.mealService.eventDay']),
                    null,
                    ! $markBookingCompleted
                );
            }

            $ongoing = $this->tracking($booking, 'ongoing');
            $ongoing->update([
                'progress_percentage' => 100,
                'stage_completed_at' => now(),
                'completed_by' => auth()->id(),
            ]);

            $completed = $this->tracking($booking, 'completed');
            $existingMetadata = json_decode((string) $completed->notes, true);
            $existingMetadata = is_array($existingMetadata) ? $existingMetadata : [];
            $now = now();
            $eventCompletedAt = $existingMetadata['event_completed_at']
                ?? $existingMetadata['completed_at']
                ?? ($completed->stage_completed_at ? (string) $completed->stage_completed_at : null)
                ?? $now->toDateTimeString();
            $wasDebtBookingEvent = (bool) ($existingMetadata['debt_booking_event'] ?? false)
                || (bool) ($completionMetadata['debt_booking_event'] ?? false)
                || (bool) ($existingMetadata['was_debt_booking_event'] ?? false);
            $metadata = array_merge($existingMetadata, $completionMetadata, [
                'event_completed' => true,
                'booking_fully_completed' => $markBookingCompleted,
                'debt_booking_event' => (bool) ($completionMetadata['debt_booking_event'] ?? $existingMetadata['debt_booking_event'] ?? false),
                'was_debt_booking_event' => $wasDebtBookingEvent,
                'outstanding_balance' => (float) ($completionMetadata['outstanding_balance'] ?? $existingMetadata['outstanding_balance'] ?? 0),
                'event_completed_at' => $eventCompletedAt,
                'booking_completed_at' => $markBookingCompleted
                    ? $now->toDateTimeString()
                    : ($existingMetadata['booking_completed_at'] ?? null),
                'debt_resolved_at' => $markBookingCompleted
                    && $wasDebtBookingEvent
                    && (float) ($completionMetadata['outstanding_balance'] ?? 0) <= 0.01
                    ? $now->toDateTimeString()
                    : ($existingMetadata['debt_resolved_at'] ?? null),
                'completed_at' => $eventCompletedAt,
                'approved_by' => $completionMetadata['approved_by'] ?? auth()->id(),
            ]);
            $completed->update([
                'progress_percentage' => 100,
                'stage_started_at' => $completed->stage_started_at ?? $now,
                'stage_completed_at' => $completed->stage_completed_at ?? $now,
                'completed_by' => $completed->completed_by ?? auth()->id(),
                'notes' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        });
    }

    /**
     * Get event equipment
     */
    public function getEventEquipment(Booking $booking): array
    {
        $booking->loadMissing(['equipment.equipment', 'serviceEvent.customer.person', 'serviceEvent.eventType']);
        $customer = $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown';
        $eventName = $booking->serviceEvent?->eventType?->name ?? 'Event';

        return [
            'display_name' => "{$customer} - {$eventName}",
            'equipment' => $booking->equipment->map(fn($row) => [
                'id' => $row->booking_equipment_id,
                'booking_equipment_id' => $row->booking_equipment_id,
                'booking_id' => $row->booking_id,
                'equipment_id' => $row->equipment_id,
                'equipment' => $row->equipment,
                'name' => $row->equipment?->name,
                'quantity_reserved' => (int) $row->quantity_reserved,
                'quantity_used' => (int) $row->quantity_used,
                'quantity_damaged' => (int) $row->quantity_damaged,
                'quantity_missing' => (int) $row->quantity_missing,
                'rental_start_date' => $row->rental_start_date?->toDateString(),
                'rental_end_date' => $row->rental_end_date?->toDateString(),
                'checked_out_date' => $row->checked_out_date?->toDateString(),
                'checked_in_date' => $row->checked_in_date?->toDateString(),
                'status' => $row->status,
                'is_out_approved' => (bool) $row->is_out_approved,
                'out_approved_at' => $row->out_approved_at?->toDateTimeString(),
                'checked_out_by' => $row->checked_out_by,
                'returned_by' => $row->returned_by,
                'return_notes' => $row->return_notes,
                'condition_out' => $row->condition_notes_out,
                'condition_in' => $row->condition_notes_in,
            ])->values()->all(),
        ];
    }

    /**
     * Get event sessions
     */
    public function getSessions(Booking $booking): array
    {
        $progress = $this->getMultiDayProgress($booking);
        $sessions = collect($progress['days'] ?? [])->flatMap(function (array $day) {
            return collect($day['sessions'] ?? [])->map(fn(array $session) => array_merge($session, [
                'day' => (int) ($session['day'] ?? $day['day'] ?? 1),
                'date' => $session['date'] ?? $day['date'] ?? null,
            ]));
        })->values()->all();

        return [
            'display_name' => $progress['display_name'] ?? $booking->booking_no,
            'current_day' => $progress['current_day'] ?? 1,
            'total_days' => $progress['total_days'] ?? 1,
            'sessions' => $sessions,
        ];
    }

    /**
     * Add session to event
     */
    public function addSession(Booking $booking, array $data): array
    {
        $progress = $this->getMultiDayProgress($booking);
        $day = (int) ($data['day'] ?? $progress['current_day'] ?? 1);
        if ($day < 1 || $day > (int) ($progress['total_days'] ?? 1)) {
            throw ValidationException::withMessages(['day' => 'Invalid event day.']);
        }

        $session = [
            'id' => (string) Str::uuid(),
            'session_id' => null,
            'day' => $day,
            'date' => $progress['days'][$day - 1]['date'] ?? null,
            'title' => (string) ($data['title'] ?? $data['name'] ?? 'Event Session'),
            'name' => (string) ($data['name'] ?? $data['title'] ?? 'Event Session'),
            'start_time' => $data['start_time'] ?? null,
            'end_time' => $data['end_time'] ?? null,
            'location' => $data['location'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => $data['status'] ?? 'scheduled',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];
        $session['session_id'] = $session['id'];

        $this->mutateMetadata($booking, 'ongoing', function (array $metadata) use ($day, $session): array {
            $key = (string) $day;
            $metadata['daily_progress'][$key]['sessions'] ??= [];
            $metadata['daily_progress'][$key]['sessions'][] = $session;
            return $metadata;
        });

        return $session;
    }

    /**
     * Update session status
     */
    public function updateSessionStatus(Booking $booking, string $sessionId, string $status): array
    {
        $updated = null;
        $this->mutateMetadata($booking, 'ongoing', function (array $metadata) use ($sessionId, $status, &$updated): array {
            foreach (($metadata['daily_progress'] ?? []) as $dayKey => $day) {
                foreach (($day['sessions'] ?? []) as $sessionKey => $session) {
                    $id = (string) ($session['session_id'] ?? $session['id'] ?? '');
                    if ($id === $sessionId) {
                        $session['status'] = $status;
                        $session['updated_at'] = now()->toIso8601String();
                        $metadata['daily_progress'][$dayKey]['sessions'][$sessionKey] = $session;
                        $updated = $session;
                        break 2;
                    }
                }
            }
            return $metadata;
        });

        if (! $updated) {
            throw ValidationException::withMessages(['session' => 'Event session not found.']);
        }

        return $updated;
    }

    /**
     * Delete session
     */
    public function deleteSession(Booking $booking, string $sessionId): void
    {
        $deleted = false;
        $this->mutateMetadata($booking, 'ongoing', function (array $metadata) use ($sessionId, &$deleted): array {
            foreach (($metadata['daily_progress'] ?? []) as $dayKey => $day) {
                $remaining = collect($day['sessions'] ?? [])->reject(function (array $session) use ($sessionId, &$deleted) {
                    $matches = (string) ($session['session_id'] ?? $session['id'] ?? '') === $sessionId;
                    $deleted = $deleted || $matches;
                    return $matches;
                })->values()->all();
                $metadata['daily_progress'][$dayKey]['sessions'] = $remaining;
            }
            return $metadata;
        });

        if (! $deleted) {
            throw ValidationException::withMessages(['session' => 'Event session not found.']);
        }
    }

    /**
     * Get live status
     */
    public function liveStatus(Booking $booking): array
    {
        $tracking = $this->tracking($booking, 'ongoing');
        $metadata = json_decode((string) $tracking->notes, true);
        $metadata = is_array($metadata) ? $metadata : [];
        return [
            'current_phase' => $booking->serviceEvent?->status ?? $booking->booking_status,
            'progress' => (int) $tracking->progress_percentage,
            'next_milestone' => $booking->booking_status === 'completed' ? 'Completed' : 'Event completion',
            'is_done' => (bool) ($metadata['event_done'] ?? false),
            'done_at' => $metadata['event_done_at'] ?? null,
            'issues' => array_values($metadata['issues'] ?? []),
            'notes' => (string) ($metadata['live_notes'] ?? ''),
            'current_day' => $metadata['current_day'] ?? 1,
            'total_days' => $metadata['total_days'] ?? 1,
            'daily_progress' => $metadata['daily_progress'] ?? [],
        ];
    }

    /**
     * Update live status
     */
    public function updateLiveStatus(Booking $booking, array $data): array
    {
        $phase = $data['current_phase'] ?? null;
        if ($phase === 'cancelled') {
            throw ValidationException::withMessages([
                'current_phase' => 'Use the event cancellation action so stock, order, invoice and calendar reservations are released safely.',
            ]);
        }
        if ($phase === 'completed') {
            $this->completeEvent($booking);
        } elseif (in_array($phase, ['pending', 'confirmed', 'ongoing'], true)) {
            DB::transaction(function () use ($booking, $phase) {
                $booking->update(['booking_status' => $phase]);
                $booking->serviceEvent?->update(['status' => $phase]);
            });
        }
        $metadata = $this->mutateMetadata($booking, 'ongoing', function (array $metadata) use ($data): array {
            if (array_key_exists('notes', $data)) {
                $metadata['live_notes'] = (string) ($data['notes'] ?? '');
            }
            if (array_key_exists('issues', $data)) {
                $metadata['issues'] = is_array($data['issues']) ? $data['issues'] : [];
            }
            if (array_key_exists('current_day', $data)) {
                $metadata['current_day'] = (int) $data['current_day'];
            }
            if (array_key_exists('is_done', $data)) {
                $isDone = (bool) $data['is_done'];
                $metadata['event_done'] = $isDone;
                $metadata['event_done_at'] = $isDone ? now()->toDateTimeString() : null;
                $metadata['event_done_by'] = $isDone ? auth()->id() : null;
            }
            return $metadata;
        });
        $tracking = $this->tracking($booking, 'ongoing');
        if (array_key_exists('progress', $data)) {
            $tracking->update(['progress_percentage' => (int) $data['progress']]);
        }
        return $this->liveStatus($booking->fresh('serviceEvent'));
    }

    /**
     * Get metadata for a booking stage
     */
    private function metadata(Booking $booking, string $stage): array
    {
        $decoded = json_decode((string) $this->tracking($booking, $stage)->notes, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Mutate metadata for a booking stage
     */
    private function mutateMetadata(Booking $booking, string $stage, callable $callback): array
    {
        return DB::transaction(function () use ($booking, $stage, $callback) {
            $tracking = EventTracking::where('booking_id', $booking->booking_id)
                ->where('stage', $stage)
                ->lockForUpdate()
                ->first();

            if (!$tracking) {
                $tracking = EventTracking::create([
                    'booking_id' => $booking->booking_id,
                    'stage' => $stage,
                    'progress_percentage' => 0,
                    'stage_started_at' => now(),
                ]);
            }

            $metadata = json_decode((string) $tracking->notes, true);
            $metadata = is_array($metadata) ? $metadata : [];
            $metadata = $callback($metadata);
            $tracking->update(['notes' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

            return $metadata;
        });
    }

    /**
     * Get or create tracking record
     */
    private function tracking(Booking $booking, string $stage): EventTracking
    {
        return EventTracking::firstOrCreate(
            ['booking_id' => $booking->booking_id, 'stage' => $stage],
            ['progress_percentage' => 0, 'stage_started_at' => now()]
        );
    }

    /**
     * Normalize delivery status
     */
    private function deliveryStatus(string $status): string
    {
        return match ($status) {
            'departed' => 'picked_up',
            'en_route' => 'in_transit',
            'arrived' => 'arriving',
            'completed' => 'delivered',
            default => in_array($status, ['pending', 'assigned', 'preparing', 'picked_up', 'in_transit', 'arriving', 'delivered', 'failed', 'cancelled'], true)
                ? $status
                : 'pending',
        };
    }

    /**
     * Synchronize event statuses
     */
    public function synchronizeEventStatuses(): void
    {
        ServiceEvent::query()
            ->where('status', 'confirmed')
            ->whereDate('event_date', '<=', today())
            ->whereRaw('COALESCE(event_end_date, event_date) >= ?', [today()->toDateString()])
            ->whereHas('booking', fn($query) => $query->where('booking_status', 'confirmed'))
            ->update(['status' => 'ongoing', 'updated_at' => now()]);
    }
}
