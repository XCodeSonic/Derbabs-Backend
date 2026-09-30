<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingEquipment;
use App\Models\Equipment;
use Illuminate\Support\Facades\DB;

class EquipmentService
{
    /**
     * Check equipment availability for a date range
     */
    public function checkAvailability(int $equipmentId, string $startDate, string $endDate): array
    {
        $equipment = Equipment::find($equipmentId);
        
        if (!$equipment) {
            return ['available' => 0, 'total' => 0, 'message' => 'Equipment not found'];
        }
        
        $reservedQuantity = BookingEquipment::where('equipment_id', $equipmentId)
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('rental_start_date', [$startDate, $endDate])
                    ->orWhereBetween('rental_end_date', [$startDate, $endDate])
                    ->orWhere(function ($q) use ($startDate, $endDate) {
                        $q->where('rental_start_date', '<=', $startDate)
                            ->where('rental_end_date', '>=', $endDate);
                    });
            })
            ->whereIn('status', ['reserved', 'checked_out'])
            ->sum('quantity_reserved');
        
        $totalAvailable = $equipment->total_quantity - $reservedQuantity;
        
        return [
            'available' => max(0, $totalAvailable),
            'total' => $equipment->total_quantity,
            'reserved' => $reservedQuantity,
            'is_available' => $totalAvailable > 0,
            'equipment' => [
                'id' => $equipment->equipment_id,
                'name' => $equipment->name,
                'category' => $equipment->category,
                'condition' => $equipment->condition,
            ],
        ];
    }

    /**
     * Reserve equipment for booking
     */
    public function reserveEquipment(Booking $booking, int $equipmentId, int $quantity, string $startDate, string $endDate): BookingEquipment
    {
        // Check availability first
        $availability = $this->checkAvailability($equipmentId, $startDate, $endDate);
        
        if (!$availability['is_available'] || $availability['available'] < $quantity) {
            throw new \Exception("Equipment not available for the selected dates. Available: {$availability['available']}, Requested: {$quantity}");
        }
        
        $equipment = Equipment::find($equipmentId);
        
        return BookingEquipment::create([
            'booking_id' => $booking->booking_id,
            'equipment_id' => $equipmentId,
            'quantity_reserved' => $quantity,
            'quantity_used' => 0,
            'quantity_damaged' => 0,
            'quantity_missing' => 0,
            'rental_start_date' => $startDate,
            'rental_end_date' => $endDate,
            'rental_price_at_booking' => $equipment?->rental_price ?? 0,
            'status' => 'reserved',
        ]);
    }

    /**
     * Get equipment warnings (low availability, upcoming events, etc.)
     */
    public function getEquipmentWarnings(): array
    {
        $warnings = [];
        
        // Check low availability equipment
        $equipment = Equipment::all();
        
        foreach ($equipment as $item) {
            $availability = $this->checkAvailability($item->equipment_id, now()->toDateString(), now()->addDays(7)->toDateString());
            
            if ($availability['available'] < 5 && $availability['total'] > 0) {
                $percentageAvailable = ($availability['available'] / $availability['total']) * 100;
                
                if ($percentageAvailable < 20) {
                    $warnings[] = [
                        'type' => 'low_availability',
                        'equipment_id' => $item->equipment_id,
                        'name' => $item->name,
                        'message' => "Low availability for {$item->name} - Only {$availability['available']} of {$availability['total']} available for next 7 days",
                        'severity' => 'warning',
                        'available' => $availability['available'],
                        'total' => $availability['total'],
                    ];
                }
            }
        }
        
        // Check upcoming events that need equipment
        $upcomingEvents = Booking::with(['serviceEvent', 'equipment.equipment'])
            ->whereIn('booking_status', ['confirmed', 'pending_approval'])
            ->whereHas('serviceEvent', function ($query) {
                $query->whereDate('event_date', '>=', now())
                    ->whereDate('event_date', '<=', now()->addDays(14));
            })
            ->get();
        
        foreach ($upcomingEvents as $event) {
            foreach ($event->equipment as $eq) {
                if ($eq->status === 'reserved') {
                    $availability = $this->checkAvailability(
                        $eq->equipment_id,
                        $event->serviceEvent->event_date->toDateString(),
                        $event->serviceEvent->event_date->toDateString()
                    );
                    
                    if (!$availability['is_available']) {
                        $warnings[] = [
                            'type' => 'equipment_conflict',
                            'event_id' => $event->booking_id,
                            'event_name' => $event->serviceEvent?->event_type?->name ?? 'Event',
                            'equipment_name' => $eq->equipment?->name,
                            'message' => "Equipment conflict for {$event->booking_no} - {$eq->equipment?->name} may not be available",
                            'severity' => 'critical',
                            'event_date' => $event->serviceEvent?->event_date?->toDateString(),
                        ];
                    }
                }
            }
        }
        
        return $warnings;
    }

    /**
     * Get maintenance schedule
     */
    public function getMaintenanceSchedule(): array
    {
        $equipment = Equipment::where('condition', '!=', 'Excellent')
            ->orWhereNotNull('last_maintenance')
            ->get();
        
        $schedule = [];
        
        foreach ($equipment as $item) {
            $maintenanceNeeded = false;
            $reason = '';
            
            if ($item->condition === 'Poor') {
                $maintenanceNeeded = true;
                $reason = 'Condition is Poor - Immediate maintenance required';
            } elseif ($item->condition === 'Fair') {
                $maintenanceNeeded = true;
                $reason = 'Condition is Fair - Maintenance recommended';
            } elseif ($item->last_maintenance && $item->last_maintenance->diffInMonths(now()) >= 6) {
                $maintenanceNeeded = true;
                $reason = 'Last maintenance was ' . $item->last_maintenance->diffInMonths(now()) . ' months ago';
            }
            
            if ($maintenanceNeeded) {
                $schedule[] = [
                    'equipment_id' => $item->equipment_id,
                    'name' => $item->name,
                    'category' => $item->category,
                    'condition' => $item->condition,
                    'last_maintenance' => $item->last_maintenance?->toDateString(),
                    'maintenance_needed' => true,
                    'reason' => $reason,
                    'priority' => $item->condition === 'Poor' ? 'high' : ($item->condition === 'Fair' ? 'medium' : 'low'),
                ];
            }
        }
        
        return $schedule;
    }

    /**
     * Get equipment utilization report
     */
    public function getUtilizationReport(?string $startDate = null, ?string $endDate = null): array
    {
        $query = BookingEquipment::with('equipment');
        
        if ($startDate) {
            $query->whereDate('rental_start_date', '>=', $startDate);
        }
        
        if ($endDate) {
            $query->whereDate('rental_end_date', '<=', $endDate);
        }
        
        $reservations = $query->get();
        
        $utilization = [];
        
        foreach ($reservations as $reservation) {
            $equipmentId = $reservation->equipment_id;
            
            if (!isset($utilization[$equipmentId])) {
                $utilization[$equipmentId] = [
                    'equipment_id' => $equipmentId,
                    'name' => $reservation->equipment?->name ?? 'Unknown',
                    'total_reserved' => 0,
                    'total_used' => 0,
                    'total_damaged' => 0,
                    'total_missing' => 0,
                    'reservation_count' => 0,
                    'total_revenue' => 0,
                ];
            }
            
            $utilization[$equipmentId]['total_reserved'] += $reservation->quantity_reserved;
            $utilization[$equipmentId]['total_used'] += $reservation->quantity_used;
            $utilization[$equipmentId]['total_damaged'] += $reservation->quantity_damaged;
            $utilization[$equipmentId]['total_missing'] += $reservation->quantity_missing;
            $utilization[$equipmentId]['reservation_count']++;
            $utilization[$equipmentId]['total_revenue'] += $reservation->rental_price_at_booking * $reservation->quantity_reserved;
        }
        
        // Calculate utilization rate for each equipment
        foreach ($utilization as &$item) {
            $totalAvailable = Equipment::find($item['equipment_id'])?->total_quantity ?? 1;
            $item['utilization_rate'] = $totalAvailable > 0 ? round(($item['total_used'] / ($totalAvailable * max($item['reservation_count'], 1))) * 100, 2) : 0;
        }
        
        return array_values($utilization);
    }
}