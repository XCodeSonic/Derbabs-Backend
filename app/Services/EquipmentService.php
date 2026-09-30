<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingEquipment;
use App\Models\Equipment;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class EquipmentService
{
    /**
     * Check equipment availability for a specific date
     */
    public function checkAvailability(int $equipmentId, string $date, ?int $excludeReservationId = null): array
    {
        $equipment = Equipment::find($equipmentId);
        if (! $equipment) {
            return ['available' => 0, 'total' => 0, 'reserved' => 0, 'is_available' => false, 'message' => 'Equipment not found.'];
        }

        // Get all reservations for this equipment on this specific date
        $reserved = BookingEquipment::where('equipment_id', $equipmentId)
            ->when($excludeReservationId, fn ($query) => $query->where('booking_equipment_id', '!=', $excludeReservationId))
            ->whereIn('status', ['reserved', 'checked_out'])
            ->where(function ($query) use ($date) {
                $query->whereDate('rental_start_date', '<=', $date)
                    ->whereDate('rental_end_date', '>=', $date);
            })
            ->sum('quantity_reserved');
        
        $available = max(0, (int) $equipment->total_quantity - (int) $reserved);

        return [
            'available' => $available,
            'total' => (int) $equipment->total_quantity,
            'reserved' => (int) $reserved,
            'is_available' => $available > 0,
            'equipment' => [
                'id' => $equipment->equipment_id,
                'name' => $equipment->name,
                'category' => $equipment->category,
                'condition' => $equipment->condition,
            ],
        ];
    }

    /**
     * Check equipment availability for a date range
     */
    public function checkAvailabilityRange(int $equipmentId, string $startDate, string $endDate, ?int $excludeReservationId = null): array
    {
        $equipment = Equipment::find($equipmentId);
        if (! $equipment) {
            return ['available' => 0, 'total' => 0, 'reserved' => 0, 'is_available' => false, 'message' => 'Equipment not found.'];
        }

        $reserved = BookingEquipment::where('equipment_id', $equipmentId)
            ->when($excludeReservationId, fn ($query) => $query->where('booking_equipment_id', '!=', $excludeReservationId))
            ->whereIn('status', ['reserved', 'checked_out'])
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereDate('rental_start_date', '<=', $endDate)
                    ->whereDate('rental_end_date', '>=', $startDate);
            })
            ->sum('quantity_reserved');
        
        $available = max(0, (int) $equipment->total_quantity - (int) $reserved);

        return [
            'available' => $available,
            'total' => (int) $equipment->total_quantity,
            'reserved' => (int) $reserved,
            'is_available' => $available > 0,
            'equipment' => [
                'id' => $equipment->equipment_id,
                'name' => $equipment->name,
                'category' => $equipment->category,
                'condition' => $equipment->condition,
            ],
        ];
    }

    /**
     * Reserve equipment for a specific date or date range
     */
    public function reserveEquipment(Booking $booking, int $equipmentId, int $quantity, string $startDate, string $endDate): BookingEquipment
    {
        $existing = BookingEquipment::where('booking_id', $booking->booking_id)
            ->where('equipment_id', $equipmentId)
            ->first();
        
        // Check availability for the date range
        $availability = $this->checkAvailabilityRange($equipmentId, $startDate, $endDate, $existing?->booking_equipment_id);
        
        if ($availability['available'] < $quantity) {
            throw ValidationException::withMessages([
                'quantity_reserved' => "Equipment is not available for the selected dates. Available: {$availability['available']}; requested: {$quantity}.",
            ]);
        }

        return BookingEquipment::updateOrCreate(
            ['booking_id' => $booking->booking_id, 'equipment_id' => $equipmentId],
            [
                'quantity_reserved' => $quantity,
                'quantity_used' => 0,
                'quantity_damaged' => 0,
                'quantity_missing' => 0,
                'rental_start_date' => $startDate,
                'rental_end_date' => $endDate,
                'rental_price_at_booking' => 0,
                'status' => 'reserved',
            ]
        );
    }

    /**
     * Check-in equipment (return)
     */
    public function checkInEquipment(BookingEquipment $bookingEquipment, array $data): BookingEquipment
    {
        $bookingEquipment->update([
            'status' => 'returned',
            'checked_in_date' => now(),
            'condition_notes_in' => $data['condition_in'] ?? null,
            'quantity_used' => $data['quantity_used'] ?? 0,
            'quantity_damaged' => $data['quantity_damaged'] ?? 0,
            'quantity_missing' => $data['quantity_missing'] ?? 0,
        ]);

        // Equipment quantities are derived from booking_equipment rows.
        // Do not update non-existing equipment counters such as reserved_quantity/damaged/missing.
        $equipment = Equipment::find($bookingEquipment->equipment_id);
        if ($equipment && (($data['quantity_damaged'] ?? 0) > 0 || ($data['quantity_missing'] ?? 0) > 0)) {
            $equipment->update(['condition' => 'Fair']);
        }

        return $bookingEquipment->fresh();
    }

    /**
     * Get equipment warnings
     */
    public function getEquipmentWarnings(): array
    {
        return Equipment::where('is_active', true)->get()->map(function (Equipment $equipment): array {
            $availability = $this->checkAvailability($equipment->equipment_id, today()->toDateString());
            return [
                'equipment_id' => $equipment->equipment_id,
                'name' => $equipment->name,
                'available' => $availability['available'],
                'total' => $availability['total'],
                'severity' => $availability['available'] <= 0 ? 'critical' : ($availability['available'] < 5 ? 'warning' : 'ok'),
            ];
        })->filter(fn ($row) => $row['severity'] !== 'ok')->values()->all();
    }

    /**
     * Get equipment reservations by date
     */
    public function getReservationsByDate(string $date): array
    {
        $reservations = BookingEquipment::with(['equipment', 'booking.serviceEvent.customer.person'])
            ->whereDate('rental_start_date', '<=', $date)
            ->whereDate('rental_end_date', '>=', $date)
            ->whereIn('status', ['reserved', 'checked_out'])
            ->get();

        return $reservations->map(function ($reservation) {
            return [
                'id' => $reservation->booking_equipment_id,
                'equipment_name' => $reservation->equipment?->name ?? 'Unknown',
                'quantity' => $reservation->quantity_reserved,
                'booking_no' => $reservation->booking?->booking_no ?? 'N/A',
                'customer_name' => $reservation->booking?->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                'start_date' => $reservation->rental_start_date?->toDateString(),
                'end_date' => $reservation->rental_end_date?->toDateString(),
                'status' => $reservation->status,
                'checked_out_date' => $reservation->checked_out_date?->toDateString(),
                'checked_in_date' => $reservation->checked_in_date?->toDateString(),
            ];
        })->toArray();
    }

    /**
     * Get equipment that needs preventive maintenance.
     */
    public function getMaintenanceSchedule(): array
    {
        return Equipment::where('is_active', true)
            ->get()
            ->map(function (Equipment $equipment): ?array {
                $lastMaintenance = $equipment->last_maintenance ? Carbon::parse($equipment->last_maintenance) : null;
                $monthsSince = $lastMaintenance ? $lastMaintenance->diffInMonths(now()) : null;
                $condition = $equipment->condition ?: 'Good';

                $needsMaintenance = in_array($condition, ['Fair', 'Poor'], true)
                    || $lastMaintenance === null
                    || $monthsSince >= 6;

                if (! $needsMaintenance) {
                    return null;
                }

                $priority = $condition === 'Poor' ? 'critical' : ($condition === 'Fair' || $lastMaintenance === null ? 'high' : 'medium');
                $reason = $condition === 'Poor'
                    ? 'Poor condition'
                    : ($condition === 'Fair' ? 'Fair condition' : ($lastMaintenance === null ? 'No maintenance record' : "Last serviced {$monthsSince} month(s) ago"));

                return [
                    'equipment_id' => $equipment->equipment_id,
                    'name' => $equipment->name,
                    'category' => $equipment->category,
                    'condition' => $condition,
                    'last_maintenance' => $lastMaintenance?->toDateString(),
                    'next_maintenance' => $lastMaintenance ? $lastMaintenance->copy()->addMonths(6)->toDateString() : now()->toDateString(),
                    'priority' => $priority,
                    'reason' => $reason,
                ];
            })
            ->filter()
            ->sortBy(function ($row) {
                return ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3][$row['priority']] ?? 9;
            })
            ->values()
            ->all();
    }

}