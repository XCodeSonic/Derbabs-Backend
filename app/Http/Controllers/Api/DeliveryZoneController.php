<?php

namespace App\Http\Controllers\Api;

use App\Models\DeliveryZone;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeliveryZoneController extends Controller
{
    public function index(Request $request)
    {
        $query = DeliveryZone::query();

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active') && $request->input('is_active') !== '') {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->boolean('all')) {
            return $this->ok($query->orderBy('name')->get());
        }

        return $this->ok($query->latest('delivery_zone_id')->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);

        $zone = DeliveryZone::create($data);
        $this->logDeliveryAction('delivery_location_created', $zone, null, $zone->getAttributes());

        return $this->ok($zone, 'Delivery location created successfully');
    }

    public function show(DeliveryZone $deliveryZone)
    {
        return $this->ok($deliveryZone);
    }

    public function update(Request $request, DeliveryZone $deliveryZone)
    {
        $oldValues = $deliveryZone->getAttributes();
        $data = $this->validatePayload($request, $deliveryZone);
        $deliveryZone->update($data);

        $this->logDeliveryAction('delivery_fee_changed', $deliveryZone, $oldValues, $deliveryZone->fresh()->getAttributes());

        return $this->ok($deliveryZone->fresh(), 'Delivery location updated successfully');
    }

    public function destroy(DeliveryZone $deliveryZone)
    {
        $oldValues = $deliveryZone->getAttributes();
        $deliveryZone->update(['is_active' => false]);

        $this->logDeliveryAction('delivery_location_deactivated', $deliveryZone, $oldValues, $deliveryZone->fresh()->getAttributes());

        return $this->ok(null, 'Delivery location deactivated successfully');
    }

    public function toggleStatus(DeliveryZone $deliveryZone)
    {
        $oldValues = $deliveryZone->getAttributes();
        $deliveryZone->update(['is_active' => ! $deliveryZone->is_active]);

        AuditLog::log(
            $deliveryZone->is_active ? 'delivery_location_activated' : 'delivery_location_deactivated',
            'delivery',
            $deliveryZone->delivery_zone_id,
            $oldValues,
            $deliveryZone->fresh()->getAttributes()
        );

        return $this->ok($deliveryZone->fresh(), 'Status updated');
    }

    private function validatePayload(Request $request, ?DeliveryZone $existing = null): array
    {
        $required = $existing ? 'sometimes' : 'required';

        return $request->validate([
            'name'          => [$required, 'string', 'max:150', Rule::unique('delivery_zones', 'name')->ignore($existing?->delivery_zone_id, 'delivery_zone_id')],
            'delivery_fee'  => ['nullable', 'numeric', 'min:0'],
            'fee'           => ['nullable', 'numeric', 'min:0'],
            'description'   => ['nullable', 'string', 'max:500'],
            'remarks'       => ['nullable', 'string', 'max:500'],
            'is_active'     => ['nullable', 'boolean'],
        ]);
    }

    private function logDeliveryAction(string $action, DeliveryZone $zone, ?array $old, array $new): void
    {
        try {
            AuditLog::log($action, 'delivery', $zone->delivery_zone_id, $old, $new, "Delivery location {$zone->name} {$action}");
        } catch (\Throwable $e) {
            // silent
        }
    }
}