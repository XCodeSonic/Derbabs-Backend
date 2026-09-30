<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingEquipment;
use App\Models\Employee;
use App\Models\EventTracking;
use App\Models\InventoryStock;
use App\Models\ServiceEvent;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EventService
{
    public function getAvailableStaff(): array
    {
        return Employee::with(['person', 'position', 'department'])
            ->where('status', 'active')
            ->get()
            ->map(fn($employee) => [
                'employee_id' => $employee->employee_id,
                'name' => $employee->full_name,
                'position' => $employee->position?->title ?? 'Staff',
                'department' => $employee->department?->name ?? 'Operations',
                'phone' => $employee->person?->phone,
                'email' => $employee->person?->email,
                'hourly_rate' => (float) $employee->hourly_rate,
            ])
            ->toArray();
    }

    public function assignStaffToEvent(Booking $booking, array $staffData): void
    {
        $tracking = EventTracking::firstOrCreate(
            ['booking_id' => $booking->booking_id, 'stage' => 'preparation'],
            ['progress_percentage' => 0]
        );

        $metadata = json_decode($tracking->notes ?? '[]', true);
        
        foreach ($staffData as $staff) {
            $employee = Employee::find($staff['staff_id']);
            if ($employee) {
                $metadata['assigned_staff'][] = [
                    'staff_id' => $staff['staff_id'],
                    'name' => $employee->full_name,
                    'role' => $staff['role'],
                    'schedule' => $staff['schedule'] ?? null,
                    'assigned_at' => now()->toIso8601String(),
                    'status' => 'pending',
                ];
            }
        }

        $tracking->update(['notes' => json_encode($metadata)]);
    }

    public function getAssignedStaff(Booking $booking): array
    {
        $tracking = EventTracking::where('booking_id', $booking->booking_id)
            ->where('stage', 'preparation')
            ->first();

        if ($tracking && $tracking->notes) {
            $metadata = json_decode($tracking->notes, true);
            return $metadata['assigned_staff'] ?? [];
        }

        return [];
    }

    public function getEventChecklist(Booking $booking): array
    {
        $booking->load(['serviceEvent', 'serviceEvent.customer.person', 'items.menuItem', 'equipment.equipment']);
        
        $checklist = [];
        $warnings = [];

        $customerName = $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown';
        $eventName = $booking->serviceEvent?->eventType?->name ?? 'Event';
        
        $displayName = "{$customerName} - {$eventName}";

        if (empty($this->getAssignedStaff($booking))) {
            $warnings[] = [
                'type' => 'staff',
                'message' => "No staff assigned to {$displayName}",
                'severity' => 'warning',
                'deadline' => $booking->serviceEvent?->event_date?->subDays(1)->toDateString(),
            ];
        }

        if ($booking->items->isEmpty()) {
            $warnings[] = [
                'type' => 'menu',
                'message' => "No menu items selected for {$displayName}",
                'severity' => 'critical',
                'deadline' => $booking->serviceEvent?->event_date?->subDays(2)->toDateString(),
            ];
        }

        foreach ($booking->equipment as $equipment) {
            $checklist[] = [
                'id' => $equipment->booking_equipment_id,
                'task' => "Prepare {$equipment->equipment?->name} ({$equipment->quantity_reserved} units) for {$displayName}",
                'assigned_to' => 'Operations Team',
                'status' => $equipment->status === 'reserved' ? 'pending' : $equipment->status,
                'deadline' => $booking->serviceEvent?->event_date?->subDays(1)->toDateString(),
            ];
        }

        if ($booking->serviceEvent?->delivery_method === 'delivery') {
            if (!$booking->serviceEvent->delivery_address) {
                $warnings[] = [
                    'type' => 'delivery',
                    'message' => "Delivery address not provided for {$displayName}",
                    'severity' => 'warning',
                    'deadline' => $booking->serviceEvent?->event_date?->subDays(1)->toDateString(),
                ];
            }
        }

        if ($booking->invoice && $booking->invoice->balance > 0) {
            $daysToEvent = $booking->serviceEvent?->event_date ? now()->diffInDays($booking->serviceEvent->event_date) : 0;
            if ($daysToEvent <= 3) {
                $warnings[] = [
                    'type' => 'payment',
                    'message' => "Outstanding balance for {$displayName}: ₱" . number_format($booking->invoice->balance, 2),
                    'severity' => 'warning',
                    'deadline' => $booking->serviceEvent?->event_date->toDateString(),
                ];
            }
        }

        return [
            'checklist' => $checklist,
            'warnings' => $warnings,
            'has_warnings' => !empty($warnings),
            'warning_count' => count($warnings),
            'display_name' => $displayName,
            'customer_name' => $customerName,
            'event_name' => $eventName,
        ];
    }

    public function getMultiDayProgress(Booking $booking): array
    {
        $serviceEvent = $booking->serviceEvent;
        $customerName = $serviceEvent?->customer?->person?->full_name ?? 'Unknown';
        
        if (!$serviceEvent || !$serviceEvent->event_date) {
            return ['days' => [], 'current_day' => 1, 'total_days' => 1, 'display_name' => "{$customerName} - Event"];
        }

        $tracking = EventTracking::where('booking_id', $booking->booking_id)
            ->where('stage', 'ongoing')
            ->first();
        
        $progressData = json_decode($tracking?->notes ?? '[]', true);
        
        $totalDays = $progressData['total_days'] ?? 1;
        $currentDay = $progressData['current_day'] ?? 1;
        $isMultiDay = $progressData['is_multi_day'] ?? false;
        
        $days = [];
        $startDate = Carbon::parse($serviceEvent->event_date);
        
        for ($i = 1; $i <= $totalDays; $i++) {
            $date = $startDate->copy()->addDays($i - 1);
            $dayProgress = $progressData['daily_progress'][$i] ?? ['completion' => 0, 'notes' => ''];
            
            $days[] = [
                'day' => $i,
                'date' => $date->toDateString(),
                'completion' => $dayProgress['completion'] ?? 0,
                'notes' => $dayProgress['notes'] ?? '',
                'is_today' => $date->isToday(),
                'is_past' => $date->isPast(),
                'is_future' => $date->isFuture(),
                'attendance' => $dayProgress['attendance'] ?? 0,
                'registered' => $dayProgress['registered'] ?? $serviceEvent->guests_count,
                'menu_items' => $dayProgress['menu_items'] ?? [],
            ];
        }
        
        $eventName = $serviceEvent->eventType?->name ?? 'Event';
        
        return [
            'days' => $days,
            'current_day' => $currentDay,
            'total_days' => $totalDays,
            'start_date' => $startDate->toDateString(),
            'end_date' => $startDate->copy()->addDays($totalDays - 1)->toDateString(),
            'overall_progress' => collect($days)->avg('completion'),
            'is_multi_day' => $isMultiDay,
            'display_name' => "{$customerName} - {$eventName}",
            'customer_name' => $customerName,
            'event_name' => $eventName,
        ];
    }

    public function updateDailyProgress(Booking $booking, int $day, array $data): void
    {
        $tracking = EventTracking::firstOrCreate(
            ['booking_id' => $booking->booking_id, 'stage' => 'ongoing'],
            ['progress_percentage' => 0]
        );
        
        $progressData = json_decode($tracking->notes ?? '[]', true);
        
        if (!isset($progressData['daily_progress'])) {
            $progressData['daily_progress'] = [];
        }
        
        $progressData['daily_progress'][$day] = [
            'completion' => $data['completion'] ?? 0,
            'notes' => $data['notes'] ?? '',
            'attendance' => $data['attendance'] ?? ($progressData['daily_progress'][$day]['attendance'] ?? 0),
            'updated_at' => now()->toIso8601String(),
        ];
        
        if (isset($data['attendance'])) {
            $progressData['daily_progress'][$day]['attendance'] = $data['attendance'];
        }
        
        $progressData['current_day'] = $day;
        
        $totalProgress = collect($progressData['daily_progress'] ?? [])->avg('completion') ?? 0;
        $totalDays = $progressData['total_days'] ?? 1;
        
        $tracking->update([
            'notes' => json_encode($progressData),
            'progress_percentage' => $totalProgress,
        ]);
        
        if ($day >= $totalDays && $totalProgress >= 100) {
            $this->completeEvent($booking);
        }
    }

    public function updateAttendance(Booking $booking, int $day, int $present): void
    {
        $tracking = EventTracking::where('booking_id', $booking->booking_id)
            ->where('stage', 'ongoing')
            ->first();
        
        if ($tracking) {
            $progressData = json_decode($tracking->notes ?? '[]', true);
            
            if (!isset($progressData['daily_progress'][$day])) {
                $progressData['daily_progress'][$day] = [];
            }
            
            $progressData['daily_progress'][$day]['attendance'] = $present;
            $progressData['daily_progress'][$day]['attendance_rate'] = round(($present / ($progressData['daily_progress'][$day]['registered'] ?? $booking->serviceEvent?->guests_count ?? 1)) * 100, 2);
            
            $tracking->update(['notes' => json_encode($progressData)]);
        }
    }

    public function advanceToNextDay(Booking $booking): array
    {
        $tracking = EventTracking::where('booking_id', $booking->booking_id)
            ->where('stage', 'ongoing')
            ->first();
        
        if ($tracking) {
            $progressData = json_decode($tracking->notes ?? '[]', true);
            $currentDay = $progressData['current_day'] ?? 1;
            $totalDays = $progressData['total_days'] ?? 1;
            
            if ($currentDay < $totalDays) {
                $progressData['current_day'] = $currentDay + 1;
                $tracking->update(['notes' => json_encode($progressData)]);
                
                return [
                    'success' => true,
                    'previous_day' => $currentDay,
                    'current_day' => $currentDay + 1,
                    'total_days' => $totalDays,
                ];
            }
        }
        
        return [
            'success' => false,
            'message' => 'Event is already on the last day or no tracking found',
        ];
    }

    public function completeEvent(Booking $booking): void
    {
        $booking->update(['booking_status' => 'completed']);
        
        if ($booking->serviceEvent) {
            $booking->serviceEvent->update(['status' => 'completed']);
        }
        
        $tracking = EventTracking::where('booking_id', $booking->booking_id)
            ->where('stage', 'ongoing')
            ->first();
        
        if ($tracking) {
            $tracking->update([
                'progress_percentage' => 100,
                'stage_completed_at' => now(),
                'completed_by' => auth()->id(),
            ]);
        }
    }

    public function getEventEquipment(Booking $booking): array
    {
        $booking->load('equipment.equipment');
        $customerName = $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown';
        $eventName = $booking->serviceEvent?->eventType?->name ?? 'Event';
        
        return [
            'display_name' => "{$customerName} - {$eventName}",
            'equipment' => $booking->equipment->map(fn($eq) => [
                'id' => $eq->booking_equipment_id,
                'equipment_id' => $eq->equipment_id,
                'name' => $eq->equipment?->name,
                'quantity_reserved' => $eq->quantity_reserved,
                'quantity_used' => $eq->quantity_used,
                'quantity_damaged' => $eq->quantity_damaged,
                'quantity_missing' => $eq->quantity_missing,
                'rental_start_date' => $eq->rental_start_date?->toDateString(),
                'rental_end_date' => $eq->rental_end_date?->toDateString(),
                'checked_out_date' => $eq->checked_out_date?->toDateString(),
                'checked_in_date' => $eq->checked_in_date?->toDateString(),
                'status' => $eq->status,
                'condition_out' => $eq->condition_notes_out,
                'condition_in' => $eq->condition_notes_in,
            ])->toArray(),
        ];
    }

    public function getEventDeliveries(Booking $booking): array
    {
        $tracking = EventTracking::where('booking_id', $booking->booking_id)
            ->where('stage', 'ongoing')
            ->first();
        
        $customerName = $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown';
        $eventName = $booking->serviceEvent?->eventType?->name ?? 'Event';
        
        $deliveries = [];
        if ($tracking && $tracking->notes) {
            $metadata = json_decode($tracking->notes, true);
            $deliveries = $metadata['deliveries'] ?? [];
        }
        
        return [
            'display_name' => "{$customerName} - {$eventName}",
            'deliveries' => $deliveries,
        ];
    }

    public function updateDelivery(Booking $booking, array $data): void
    {
        $tracking = EventTracking::firstOrCreate(
            ['booking_id' => $booking->booking_id, 'stage' => 'ongoing'],
            ['progress_percentage' => 0]
        );
        
        $deliveryData = json_decode($tracking->notes ?? '[]', true);
        
        if (!isset($deliveryData['deliveries'])) {
            $deliveryData['deliveries'] = [];
        }
        
        $deliveryData['deliveries'][] = [
            'id' => uniqid(),
            'vehicle' => $data['vehicle'] ?? null,
            'driver' => $data['driver'] ?? null,
            'driver_phone' => $data['driver_phone'] ?? null,
            'eta' => $data['eta'] ?? null,
            'location' => $data['location'] ?? null,
            'status' => $data['status'] ?? 'pending',
            'created_at' => now()->toIso8601String(),
        ];
        
        $tracking->update(['notes' => json_encode($deliveryData)]);
    }

    public function updateDeliveryStatus(Booking $booking, string $deliveryId, string $status, ?string $location = null): void
    {
        $tracking = EventTracking::where('booking_id', $booking->booking_id)
            ->where('stage', 'ongoing')
            ->first();
        
        if ($tracking && $tracking->notes) {
            $deliveryData = json_decode($tracking->notes, true);
            
            foreach ($deliveryData['deliveries'] ?? [] as &$delivery) {
                if ($delivery['id'] == $deliveryId) {
                    $delivery['status'] = $status;
                    if ($location) {
                        $delivery['location'] = $location;
                    }
                    $delivery['updated_at'] = now()->toIso8601String();
                    break;
                }
            }
            
            $tracking->update(['notes' => json_encode($deliveryData)]);
        }
    }
}