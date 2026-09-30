<?php

namespace App\Http\Controllers\Api;

use App\Models\BookingEquipment;
use App\Models\Equipment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EquipmentController extends Controller
{
    /**
     * Generate sequential equipment code (EQP-0013 format)
     */
    private function generateEquipmentCode(): string
    {
        return $this->generateSequentialNumber('EQP-', Equipment::class, 'code');
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

        // Create a new instance to get the key name
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
        return $prefix . now()->format('YmdHis') . '-' . strtoupper(substr(uniqid(), -4));
    }
}
    public function index(Request $request)
    {
        try {
            $query = Equipment::query()->with('supplier');

            if ($request->filled('search')) {
                $search = trim((string) $request->input('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('category', 'like', "%{$search}%");
                });
            }

            if ($request->has('active') || $request->has('is_active')) {
                $isActive = $request->boolean($request->has('active') ? 'active' : 'is_active');
                $query->where('is_active', $isActive);
            }

            $perPage = min(max($request->integer('per_page', 20), 1), 500);
            $rows = $query->latest('equipment_id')->paginate($perPage);
            $availabilityDate = $request->filled('date') ? Carbon::parse($request->input('date'))->toDateString() : null;
            $excludeBookingId = $request->integer('booking_id') ?: null;

            $rows->getCollection()->transform(function (Equipment $equipment) use ($availabilityDate, $excludeBookingId) {
                return $this->formatEquipment($equipment, false, $availabilityDate, $excludeBookingId);
            });

            return $this->ok($rows);
            
        } catch (\Exception $e) {
            \Log::error('Equipment API Error: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
            return $this->error('Failed to fetch equipment: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $data = $this->validatePayload($request);
            $data['code'] = $data['code'] ?? $this->generateEquipmentCode();
            $equipment = Equipment::create($this->fields($data));
            return $this->ok($this->formatEquipment($equipment), 'Equipment created.');
        } catch (\Exception $e) {
            \Log::error('Equipment Store Error: ' . $e->getMessage());
            return $this->error('Failed to create equipment: ' . $e->getMessage(), 500);
        }
    }

    public function show(Equipment $equipment)
    {
        try {
            $equipment->load(['supplier', 'reservations.booking.serviceEvent.customer.person']);
            return $this->ok($this->formatEquipment($equipment, true));
        } catch (\Exception $e) {
            \Log::error('Equipment Show Error: ' . $e->getMessage());
            return $this->error('Failed to load equipment: ' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, Equipment $equipment)
    {
        try {
            $data = $this->validatePayload($request, $equipment);
            $equipment->update($this->fields($data));
            return $this->show($equipment->fresh());
        } catch (\Exception $e) {
            \Log::error('Equipment Update Error: ' . $e->getMessage());
            return $this->error('Failed to update equipment: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(Equipment $equipment)
    {
        try {
            $equipment->delete();
            return $this->ok(null, 'Equipment archived.');
        } catch (\Exception $e) {
            \Log::error('Equipment Delete Error: ' . $e->getMessage());
            return $this->error('Failed to archive equipment: ' . $e->getMessage(), 500);
        }
    }

    public function restore(int $id)
    {
        try {
            $equipment = Equipment::onlyTrashed()->findOrFail($id);
            $equipment->restore();
            return $this->ok($this->formatEquipment($equipment), 'Equipment restored.');
        } catch (\Exception $e) {
            \Log::error('Equipment Restore Error: ' . $e->getMessage());
            return $this->error('Failed to restore equipment: ' . $e->getMessage(), 500);
        }
    }

    public function stats()
    {
        try {
            $today = now()->toDateString();
            $reserved = (int) BookingEquipment::whereIn('status', ['reserved', 'checked_out'])
                ->whereDate('rental_start_date', '<=', $today)
                ->whereDate('rental_end_date', '>=', $today)
                ->sum('quantity_reserved');
            $total = (int) Equipment::where('is_active', true)->sum('total_quantity');
            
            return $this->ok([
                'total' => Equipment::where('is_active', true)->count(),
                'total_quantity' => $total,
                'available' => max(0, $total - $reserved),
                'reserved' => $reserved,
                'in_use' => (int) BookingEquipment::where('status', 'checked_out')
                    ->whereDate('rental_start_date', '<=', $today)
                    ->whereDate('rental_end_date', '>=', $today)
                    ->sum('quantity_reserved'),
                'damaged' => (int) BookingEquipment::sum('quantity_damaged'),
                'missing' => (int) BookingEquipment::sum('quantity_missing'),
            ]);
        } catch (\Exception $e) {
            \Log::error('Equipment Stats Error: ' . $e->getMessage());
            return $this->error('Failed to load stats: ' . $e->getMessage(), 500);
        }
    }

    public function history(Equipment $equipment)
    {
        try {
            return $this->ok(
                $equipment->reservations()
                    ->with('booking.serviceEvent.customer.person')
                    ->latest('booking_equipment_id')
                    ->get()
            );
        } catch (\Exception $e) {
            \Log::error('Equipment History Error: ' . $e->getMessage());
            return $this->error('Failed to load history: ' . $e->getMessage(), 500);
        }
    }

    private function validatePayload(Request $request, ?Equipment $equipment = null): array
    {
        $request->merge(array_filter([
            'name' => $request->input('name', $request->input('equipment_name')),
            'total_quantity' => $request->input('total_quantity', $request->input('total')),
        ], static fn ($value) => $value !== null));

        $required = $equipment ? 'sometimes' : 'required';
        
        return $request->validate([
            'name' => [$required, 'string', 'max:100'],
            'equipment_name' => ['nullable', 'string', 'max:100'],
            'total' => ['nullable', 'integer', 'min:0'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('equipment', 'code')->ignore($equipment?->equipment_id, 'equipment_id')],
            'category' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'total_quantity' => [$required, 'integer', 'min:0'],
            'location' => ['nullable', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'exists:suppliers,supplier_id'],
            'model' => ['nullable', 'string', 'max:100'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'condition' => ['nullable', Rule::in(['Excellent', 'Good', 'Fair', 'Poor'])],
            'last_maintenance' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'sub_category' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function fields(array $data): array
    {
        $fields = collect($data)->only([
            'name', 'code', 'category', 'description', 'total_quantity', 
            'location', 'supplier_id', 'model', 'serial_number', 'condition', 'last_maintenance'
        ])->all();
        
        if (!array_key_exists('description', $fields) && array_key_exists('notes', $data)) {
            $fields['description'] = $data['notes'];
        }
        
        if (array_key_exists('is_active', $data) || array_key_exists('active', $data)) {
            $fields['is_active'] = $data['is_active'] ?? $data['active'];
        }
        
        return $fields;
    }

    private function formatEquipment(Equipment $equipment, bool $withReservations = false, ?string $availabilityDate = null, ?int $excludeBookingId = null): array
    {
        $reservationQuery = BookingEquipment::where('equipment_id', $equipment->equipment_id)
            ->whereIn('status', ['reserved', 'checked_out']);

        if ($availabilityDate) {
            $reservationQuery->whereDate('rental_start_date', '<=', $availabilityDate)
                ->whereDate('rental_end_date', '>=', $availabilityDate);
        }

        if ($excludeBookingId) {
            $reservationQuery->where('booking_id', '!=', $excludeBookingId);
        }

        $reserved = (int) $reservationQuery->sum('quantity_reserved');
            
        $inUse = (int) BookingEquipment::where('equipment_id', $equipment->equipment_id)
            ->where('status', 'checked_out')
            ->sum('quantity_reserved');
            
        $damaged = (int) BookingEquipment::where('equipment_id', $equipment->equipment_id)
            ->sum('quantity_damaged');
            
        $missing = (int) BookingEquipment::where('equipment_id', $equipment->equipment_id)
            ->sum('quantity_missing');
        
        $totalQty = (int) $equipment->total_quantity;
        
        $formatted = [
            'id' => $equipment->equipment_id,
            'equipment_id' => $equipment->equipment_id,
            'display_id' => sprintf('EQP-%03d', $equipment->equipment_id),
            'name' => $equipment->name,
            'equipment_name' => $equipment->name,
            'code' => $equipment->code,
            'category' => $equipment->category,
            'description' => $equipment->description,
            'notes' => $equipment->description,
            'total_quantity' => $totalQty,
            'total' => $totalQty,
            'reserved' => $reserved,
            'reserved_quantity' => $reserved,
            'in_use' => $inUse,
            'in_use_quantity' => $inUse,
            'damaged' => $damaged,
            'damaged_quantity' => $damaged,
            'missing' => $missing,
            'missing_quantity' => $missing,
            'available_quantity' => max(0, $totalQty - $reserved),
            'available' => max(0, $totalQty - $reserved),
            'location' => $equipment->location,
            'supplier_id' => $equipment->supplier_id,
            'supplier' => $equipment->supplier?->name,
            'supplier_name' => $equipment->supplier?->name,
            'model' => $equipment->model,
            'serial_number' => $equipment->serial_number,
            'condition' => $equipment->condition,
            'last_maintenance' => $equipment->last_maintenance?->toDateString(),
            'is_active' => (bool) $equipment->is_active,
            'active' => (bool) $equipment->is_active,
            'status' => $reserved >= $totalQty && $totalQty > 0 ? 'in-use' : 'available',
            'created_at' => $equipment->created_at,
            'updated_at' => $equipment->updated_at,
        ];
        
        if ($withReservations) {
            $formatted['reservations'] = $equipment->reservations ?? collect();
        }
        
        return $formatted;
    }
}