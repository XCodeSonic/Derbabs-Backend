<?php

namespace App\Http\Controllers\Api;

use App\Models\Booking;
use App\Models\BookingEquipment;
use App\Models\Equipment;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\PurchaseRequest;
use App\Models\WasteRecord;
use App\Models\AuditLog;
use App\Services\EquipmentService;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class InventoryController extends Controller
{
    public function dashboard()
    {
        $today = today()->toDateString();

        $stockScope = InventoryStock::query()->whereHas('ingredient', function ($query) {
            $query->where('is_active', true);
        });

        $reservedEquipmentToday = (int) BookingEquipment::whereIn('status', ['reserved', 'checked_out'])
            ->whereDate('rental_start_date', '<=', $today)
            ->whereDate('rental_end_date', '>=', $today)
            ->sum('quantity_reserved');

        $reservedOnlyToday = (int) BookingEquipment::where('status', 'reserved')
            ->whereDate('rental_start_date', '<=', $today)
            ->whereDate('rental_end_date', '>=', $today)
            ->sum('quantity_reserved');

        $checkedOutToday = (int) BookingEquipment::where('status', 'checked_out')
            ->whereDate('rental_start_date', '<=', $today)
            ->whereDate('rental_end_date', '>=', $today)
            ->sum('quantity_reserved');

        $totalEquipment = (int) Equipment::where('is_active', true)->sum('total_quantity');

        return $this->ok([
            'products' => [
                'total_items' => Ingredient::where('is_active', true)->count(),
                'total_quantity' => (float) (clone $stockScope)->sum('current_quantity'),
                'low_stock' => (clone $stockScope)
                    ->where('current_quantity', '>', 0)
                    ->whereColumn('current_quantity', '<=', 'reorder_point')
                    ->count(),
                'out_of_stock' => (clone $stockScope)->where('current_quantity', '<=', 0)->count(),
                'expiring_soon' => (clone $stockScope)
                    ->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '>=', now())
                    ->whereDate('expiry_date', '<=', now()->addDays(7))
                    ->count(),
                'reserved' => (float) (clone $stockScope)->sum('reserved_quantity'),
            ],
            'equipment' => [
                'total_items' => Equipment::where('is_active', true)->count(),
                'total_quantity' => $totalEquipment,
                'available' => max(0, $totalEquipment - $reservedEquipmentToday),
                'reserved' => $reservedOnlyToday,
                'in_use' => $checkedOutToday,
                'damaged' => (int) BookingEquipment::sum('quantity_damaged'),
                'missing' => (int) BookingEquipment::sum('quantity_missing'),
            ],
            'purchase_requests' => PurchaseRequest::where('status', 'pending')->count(),
        ]);
    }

        public function movements(Request $request)
    {
        $limit = min(max($request->integer('per_page', 200), 1), 500);
        $query = InventoryMovement::with(['ingredient', 'performedBy.person'])->latest('movement_id');

        if (! $request->boolean('include_history')) {
            $query->where(function ($movement) {
                $movement->whereNull('reference_type')
                    ->orWhere('reference_type', '!=', 'HistoricalAnalyticsBooking');
            });
        }

        if ($request->filled('ingredient_id')) {
            $query->where('ingredient_id', $request->input('ingredient_id'));
        }

        // ⭐ Date range filter — needed for the Daily / Weekly / Monthly period indicator
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $ingredientMovements = $query->limit($limit)->get()->map(fn (InventoryMovement $movement) => $this->formatMovement($movement));

        $equipmentQuery = BookingEquipment::with(['equipment', 'booking.serviceEvent.customer.person'])
            ->whereIn('status', ['reserved', 'checked_out', 'returned', 'damaged', 'missing']);

        // ⭐ Same date filter for equipment movements
        if ($request->filled('date_from')) {
            $equipmentQuery->whereDate('updated_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $equipmentQuery->whereDate('updated_at', '<=', $request->input('date_to'));
        }

        $equipmentMovements = $equipmentQuery
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (BookingEquipment $tracking) => $this->formatEquipmentMovement($tracking));

        $rows = $ingredientMovements
            ->concat($equipmentMovements)
            ->sortByDesc(fn ($row) => $row['movement_at'] ?? '')
            ->take($limit)
            ->values();

        return $this->ok($rows);
    }

    public function recordMovement(Request $request, InventoryService $service)
    {
        $data = $request->validate([
            'ingredient_id' => ['required', 'exists:ingredients,ingredient_id'],
            'quantity_change' => ['required', 'numeric', 'not_in:0'],
            'movement_type' => ['required', Rule::in(['purchase', 'usage', 'return', 'waste', 'adjustment'])],
            'reason' => ['nullable', 'string'],
        ]);

        $quantity = (float) $data['quantity_change'];
        if (in_array($data['movement_type'], ['purchase', 'return'], true)) {
            $quantity = abs($quantity);
        } elseif (in_array($data['movement_type'], ['usage', 'waste'], true)) {
            $quantity = -abs($quantity);
        }

        $movement = $service->move((int) $data['ingredient_id'], $quantity, $data['movement_type'], $data['reason'] ?? '');
        return $this->ok($movement->load('ingredient'), 'Inventory updated.');
    }

    public function waste(Request $request, InventoryService $service)
    {
        if ($request->isMethod('post')) {
            $data = $request->validate([
                'ingredient_id' => ['required', 'exists:ingredients,ingredient_id'],
                'quantity' => ['required', 'numeric', 'min:0.001'],
                'reason' => ['required', Rule::in(['spoilage', 'expired', 'damage', 'prep_waste', 'other'])],
                'notes' => ['nullable', 'string'],
            ]);
            $row = DB::transaction(function () use ($data, $service) {
                $record = WasteRecord::create($data + ['recorded_by' => auth()->id()]);
                $movement = $service->move(
                    (int) $data['ingredient_id'],
                    -(float) $data['quantity'],
                    'waste',
                    'Waste Management: ' . ($data['notes'] ?? $data['reason'])
                );
                $movement->update([
                    'reference_type' => 'WasteRecord',
                    'reference_id' => $record->waste_record_id,
                ]);
                return $record;
            });

            return $this->ok($this->formatWaste($row->load(['ingredient', 'recorder.person'])), 'Waste recorded.');
        }

        $rows = WasteRecord::with(['ingredient', 'recorder.person'])
            ->latest('waste_record_id')
            ->paginate(min(max($request->integer('per_page', 20), 1), 500));
        $rows->getCollection()->transform(fn (WasteRecord $record) => $this->formatWaste($record));

        return $this->ok($rows);
    }

    public function purchaseRequests(Request $request)
    {
        if ($request->isMethod('post')) {
            $data = $request->validate([
                'ingredient_id' => ['required', 'exists:ingredients,ingredient_id'],
                'supplier_id' => ['nullable', 'exists:suppliers,supplier_id'],
                'booking_id' => ['nullable', 'exists:bookings,booking_id'],
                'quantity' => ['required', 'numeric', 'min:0.001'],
                'urgency' => ['nullable', Rule::in(['normal', 'urgent', 'critical'])],
                'expected_delivery' => ['nullable', 'date'],
                'notes' => ['nullable', 'string'],
            ]);
            [$row, $created] = DB::transaction(function () use ($data) {
                $existing = PurchaseRequest::query()
                    ->where('ingredient_id', $data['ingredient_id'])
                    ->when(
                        array_key_exists('booking_id', $data),
                        fn ($query) => $query->where('booking_id', $data['booking_id']),
                        fn ($query) => $query->whereNull('booking_id')
                    )
                    ->whereIn('status', ['pending', 'approved', 'ordered'])
                    ->lockForUpdate()
                    ->latest('purchase_request_id')
                    ->first();

                if ($existing) {
                    $existing->update([
                        'quantity' => $data['quantity'],
                        'supplier_id' => $data['supplier_id'] ?? $existing->supplier_id,
                        'urgency' => $data['urgency'] ?? $existing->urgency ?? 'normal',
                        'expected_delivery' => $data['expected_delivery'] ?? $existing->expected_delivery,
                        'notes' => $data['notes'] ?? $existing->notes,
                    ]);
                    return [$existing, false];
                }

                            $user = auth()->user();
                $adminRoles = ['admin', 'administrator', 'super_admin', 'superadmin', 'super-admin', 'owner'];
                $isAdmin = $user && $user->hasAnyRole($adminRoles);
                $isShortage = ! empty($data['booking_id']);

                // ⭐ Admin-created shortages for a confirmed booking are auto-approved.
                $status = ($isAdmin && $isShortage) ? 'approved' : 'pending';

                $request = PurchaseRequest::create($data + [
                    'pr_number' => 'PRQ-' . now()->format('YmdHisv') . '-' . random_int(100, 999),
                    'status' => $status,
                    'urgency' => $data['urgency'] ?? 'normal',
                    'requested_by' => $user?->user_id ?? 1,
                ]);

                // ⭐ Audit log the auto-approval so it's traceable.
                if ($status === 'approved') {
                    AuditLog::log(
                        'update',
                        'inventory',
                        $request->purchase_request_id,
                        ['status' => 'pending'],
                        ['status' => 'approved'],
                        'Auto-approved: Admin created shortage for confirmed order'
                    );
                }

                return [$request, true];
            });

            return $this->ok(
                $this->formatPurchaseRequest($row->load(['ingredient', 'supplier', 'requester.person', 'booking.serviceEvent.customer.person', 'booking.serviceEvent.eventType'])),
                $created ? 'Purchase request created.' : 'Existing purchase request updated.'
            );
        }

        $query = PurchaseRequest::with(['ingredient', 'supplier', 'requester.person', 'booking.serviceEvent.customer.person', 'booking.serviceEvent.eventType']);

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($builder) use ($search) {
                $builder->where('pr_number', 'like', "%{$search}%")
                    ->orWhereHas('ingredient', fn ($ingredient) => $ingredient->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('booking', fn ($booking) => $booking->where('booking_no', 'like', "%{$search}%"));
            });
        }

        $rows = $query->latest('purchase_request_id')
            ->paginate(min(max($request->integer('per_page', 20), 1), 500));
        $rows->getCollection()->transform(fn (PurchaseRequest $purchaseRequest) => $this->formatPurchaseRequest($purchaseRequest));

        return $this->ok($rows);
    }

    public function showPurchaseRequest(PurchaseRequest $purchaseRequest)
    {
        return $this->ok($this->formatPurchaseRequest(
            $purchaseRequest->load(['ingredient', 'supplier', 'requester.person', 'booking.serviceEvent.customer.person', 'booking.serviceEvent.eventType'])
        ));
    }

    public function updatePurchaseRequest(Request $request, PurchaseRequest $purchaseRequest)
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'exists:suppliers,supplier_id'],
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
            'urgency' => ['nullable', Rule::in(['normal', 'urgent', 'critical'])],
            'expected_delivery' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'ordered', 'received', 'cancelled'])],
        ]);

        if (($data['status'] ?? null) === 'approved') {
            $user = auth()->user();
            $adminRoles = ['admin', 'administrator', 'super_admin', 'superadmin', 'super-admin', 'owner'];
            if (! $user || ! $user->hasAnyRole($adminRoles)) {
                return $this->fail('Only an administrator can approve purchase requests.', 403);
            }

            if ((int) $purchaseRequest->requested_by === (int) $user->user_id) {
                return $this->fail('Users cannot approve their own purchase requests.', 403);
            }
        }

        $oldStatus = $purchaseRequest->status;
        $purchaseRequest->update($data);

        if (($data['status'] ?? null) && $oldStatus !== $data['status']) {
            AuditLog::log('update', 'inventory', $purchaseRequest->purchase_request_id, ['status' => $oldStatus], ['status' => $data['status']], 'Purchase request status updated');
        }

        return $this->ok($this->formatPurchaseRequest(
            $purchaseRequest->fresh(['ingredient', 'supplier', 'requester.person', 'booking.serviceEvent.customer.person', 'booking.serviceEvent.eventType'])
        ), 'Purchase request updated.');
    }

    public function purchaseSuggestions(Request $request, InventoryService $inventoryService)
    {
        $days = min(max($request->integer('days', 30), 1), 90);
        $from = now();
        $to = now()->addDays($days);

        $bookings = Booking::with(['serviceEvent.customer.person', 'serviceEvent.eventType', 'items.menuItem.recipeIngredients.ingredient', 'items.mealService.eventDay'])
            ->whereIn('booking_status', ['approved', 'confirmed', 'ongoing'])
            ->whereHas('serviceEvent', function ($query) use ($from, $to) {
                $query->whereDate('event_date', '>=', $from->toDateString())
                    ->whereDate('event_date', '<=', $to->toDateString());
            })
            ->latest('booking_id')
            ->get()
            ->filter(fn (Booking $booking) => ! $inventoryService->hasDeductedInventory($booking));

        $suggestions = [];
        foreach ($bookings as $booking) {
            $eventDateTime = $this->eventDateTime($booking);
            $hoursToEvent = $eventDateTime ? now()->diffInHours($eventDateTime, false) : null;
            $requirements = $inventoryService->getIngredientRequirements($booking);

            foreach ($requirements as $requirement) {
                $shortage = (float) ($requirement['shortage'] ?? 0);
                if ($shortage <= 0) {
                    continue;
                }

                $existing = PurchaseRequest::where('booking_id', $booking->booking_id)
                    ->where('ingredient_id', $requirement['ingredient_id'])
                    ->whereIn('status', ['pending', 'approved', 'ordered'])
                    ->latest('purchase_request_id')
                    ->first();

                $suggestions[] = [
                    'id' => $booking->booking_id . '-' . $requirement['ingredient_id'],
                    'productId' => $requirement['ingredient_id'],
                    'ingredient_id' => $requirement['ingredient_id'],
                    'booking_id' => $booking->booking_id,
                    'booking_no' => $booking->booking_no,
                    'event_name' => $booking->serviceEvent?->eventType?->name ?? 'Event',
                    'customer_name' => $booking->serviceEvent?->customer?->person?->full_name ?? 'Customer',
                    'event_date' => optional($booking->serviceEvent?->event_date)->toDateString(),
                    'event_time' => $booking->serviceEvent?->event_time,
                    'hours_to_event' => $hoursToEvent,
                    'name' => $requirement['name'] ?? 'Unknown',
                    'unit' => $requirement['unit'] ?? 'unit',
                    'required_total' => (float) ($requirement['required_total'] ?? 0),
                    'currentStock' => (float) ($requirement['current_stock'] ?? 0),
                    'reserved' => (float) ($requirement['reserved'] ?? 0),
                    'available' => (float) ($requirement['available'] ?? 0),
                    'shortage' => $shortage,
                    'suggestedQuantity' => $shortage,
                    'reorderPoint' => null,
                    'urgency' => $hoursToEvent !== null && $hoursToEvent <= 8 ? 'critical' : ($hoursToEvent !== null && $hoursToEvent <= 24 ? 'urgent' : 'normal'),
                    'existing_purchase_request_id' => $existing?->purchase_request_id,
                    'existing_status' => $existing?->status,
                ];
            }
        }

        return $this->ok(collect($suggestions)->sortBy(function ($row) {
            return $row['hours_to_event'] ?? 999999;
        })->values()->all());
    }

    public function inventoryNotifications(Request $request, InventoryService $inventoryService, EquipmentService $equipmentService)
    {
        $notifications = [];
        $now = now();

        foreach ($inventoryService->getInventoryAlerts() as $alert) {
            $notifications[] = [
                'id' => 'stock-' . md5(($alert['ingredient'] ?? '') . ($alert['type'] ?? '')),
                'type' => in_array($alert['type'] ?? '', ['critical', 'expired'], true) ? 'danger' : 'warning',
                'message' => $alert['message'] ?? 'Inventory alert',
                'time' => $now->diffForHumans(),
                'read' => false,
                'source' => 'inventory',
            ];
        }

        foreach ($this->purchaseSuggestions(new Request(['days' => 2]), $inventoryService)->getData(true)['data'] ?? [] as $suggestion) {
            if (($suggestion['hours_to_event'] ?? 999) <= 24) {
                $notifications[] = [
                    'id' => 'purchase-' . $suggestion['id'],
                    'type' => ($suggestion['hours_to_event'] ?? 999) <= 8 ? 'danger' : 'warning',
                    'message' => "Purchase {$suggestion['name']} before {$suggestion['event_name']} ({$suggestion['booking_no']}). Shortage: {$suggestion['shortage']} {$suggestion['unit']}.",
                    'time' => isset($suggestion['hours_to_event']) ? round($suggestion['hours_to_event']) . ' hrs before event' : 'Upcoming event',
                    'read' => false,
                    'source' => 'purchase_suggestion',
                ];
            }
        }

        foreach ($equipmentService->getEquipmentWarnings() as $warning) {
            $notifications[] = [
                'id' => 'equipment-' . $warning['equipment_id'],
                'type' => $warning['severity'] === 'critical' ? 'danger' : 'warning',
                'message' => "Equipment availability warning: {$warning['name']} has {$warning['available']} available today.",
                'time' => 'Today',
                'read' => false,
                'source' => 'equipment',
            ];
        }

        return $this->ok(array_values($notifications));
    }

    public function maintenanceSchedule(EquipmentService $equipmentService)
    {
        return $this->ok($equipmentService->getMaintenanceSchedule());
    }

    public function equipmentWarnings()
    {
        $rows = Equipment::with(['reservations' => fn ($query) => $query->whereIn('status', ['reserved', 'checked_out'])])->get();
        $warnings = $rows->map(function (Equipment $equipment) {
            $reserved = (int) $equipment->reservations->sum('quantity_reserved');
            $available = max(0, (int) $equipment->total_quantity - $reserved);
            return [
                'equipment_id' => $equipment->equipment_id,
                'name' => $equipment->name,
                'total_quantity' => (int) $equipment->total_quantity,
                'reserved_quantity' => $reserved,
                'available_quantity' => $available,
                'severity' => $available <= 0 ? 'critical' : ($available <= 5 ? 'warning' : 'ok'),
            ];
        })->filter(fn ($row) => $row['severity'] !== 'ok')->values();

        return $this->ok($warnings);
    }

    public function reservations(Request $request)
    {
        $query = BookingEquipment::with(['booking.serviceEvent.customer.person', 'booking.serviceEvent.eventType', 'equipment'])
            ->whereIn('status', ['reserved', 'checked_out']);

        if (! $request->boolean('include_all')) {
            $query->whereDate('rental_end_date', '>=', today()->toDateString());
        }

        $rows = $query->orderBy('rental_start_date')
            ->paginate(min(max($request->integer('per_page', 20), 1), 500));
        $rows->getCollection()->transform(fn (BookingEquipment $tracking) => $this->formatReservation($tracking));

        return $this->ok($rows);
    }

    public function storeReservation(Request $request, EquipmentService $equipmentService)
    {
        $request->merge([
            'booking_id' => $request->input('booking_id', $request->input('event_id', $request->input('eventId'))),
            'equipment_id' => $request->input('equipment_id', $request->input('equipmentId')),
            'quantity_reserved' => $request->input('quantity_reserved', $request->input('quantity')),
            'rental_start_date' => $request->input('rental_start_date', $request->input('start_date', $request->input('startDate'))),
        ]);
        $data = $request->validate([
            'booking_id' => ['required', 'exists:bookings,booking_id'],
            'equipment_id' => ['required', 'exists:equipment,equipment_id'],
            'quantity_reserved' => ['required', 'integer', 'min:1'],
            'rental_start_date' => ['nullable', 'date'],
        ]);
        $booking = Booking::with('serviceEvent')->findOrFail($data['booking_id']);
        $eventDate = $booking->serviceEvent?->event_date?->toDateString();
        $start = $data['rental_start_date'] ?? $eventDate ?? today()->toDateString();

        if ($eventDate && Carbon::parse($start)->toDateString() !== $eventDate) {
            return $this->fail('Equipment reservation date must be the same day as the event date.', 422);
        }

        // Equipment reservations are locked to the event day only.
        $end = $start;
        $row = $equipmentService->reserveEquipment(
            $booking,
            (int) $data['equipment_id'],
            (int) $data['quantity_reserved'],
            $start,
            $end
        );
        return $this->ok($this->formatReservation(
            $row->load(['booking.serviceEvent.customer.person', 'booking.serviceEvent.eventType', 'equipment'])
        ), 'Equipment reservation created.');
    }

    public function maintenanceRecords(Request $request)
    {
        $query = AuditLog::with('user.person')
            ->where('table_name', 'equipment_maintenance')
            ->latest('audit_id');

        $rows = $query->paginate(min(max($request->integer('per_page', 20), 1), 500));
        $rows->getCollection()->transform(fn (AuditLog $record) => $this->formatMaintenance($record));

        return $this->ok($rows);
    }

    public function storeMaintenance(Request $request)
    {
        $payload = $this->validateMaintenance($request);
        $record = AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'create',
            'table_name' => 'equipment_maintenance',
            'record_id' => null,
            'old_values' => null,
            'new_values' => $payload,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
        $record->update(['record_id' => $record->audit_id]);

        if (($payload['status'] ?? null) === 'completed' && ! empty($payload['equipment_id'])) {
            Equipment::whereKey($payload['equipment_id'])->update(['last_maintenance' => $payload['completed_date'] ?? now()->toDateString()]);
        }

        return $this->ok($this->formatMaintenance($record->fresh('user.person')), 'Maintenance record created.');
    }

    public function updateMaintenance(Request $request, AuditLog $maintenance)
    {
        if ($maintenance->table_name !== 'equipment_maintenance') {
            return $this->fail('Maintenance record not found.', 404);
        }

        $payload = array_merge((array) $maintenance->new_values, $this->validateMaintenance($request, true));
        $maintenance->update([
            'action' => 'update',
            'old_values' => $maintenance->new_values,
            'new_values' => $payload,
            'user_id' => auth()->id(),
        ]);

        if (($payload['status'] ?? null) === 'completed' && ! empty($payload['equipment_id'])) {
            Equipment::whereKey($payload['equipment_id'])->update(['last_maintenance' => $payload['completed_date'] ?? now()->toDateString()]);
        }

        return $this->ok($this->formatMaintenance($maintenance->fresh('user.person')), 'Maintenance record updated.');
    }

    public function cancelMaintenance(AuditLog $maintenance)
    {
        if ($maintenance->table_name !== 'equipment_maintenance') {
            return $this->fail('Maintenance record not found.', 404);
        }

        $payload = array_merge((array) $maintenance->new_values, ['status' => 'cancelled']);
        $maintenance->update([
            'action' => 'cancel',
            'old_values' => $maintenance->new_values,
            'new_values' => $payload,
            'user_id' => auth()->id(),
        ]);

        return $this->ok($this->formatMaintenance($maintenance->fresh('user.person')), 'Maintenance record cancelled.');
    }

    public function expiringSoon()
    {
        return $this->ok(InventoryStock::with('ingredient')
            ->whereHas('ingredient', fn ($query) => $query->where('is_active', true))
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays(7))
            ->whereDate('expiry_date', '>=', now())
            ->get());
    }

    public function stockValue()
    {
        $total = InventoryStock::with('ingredient')->get()->sum(function ($stock) {
            return (float) $stock->current_quantity * (float) ($stock->ingredient?->unit_cost ?? 0);
        });

        return $this->ok([
            'total_value' => round($total, 2),
            'by_category' => Ingredient::select('category')
                ->selectRaw('SUM(inventory_stocks.current_quantity * ingredients.unit_cost) as value')
                ->join('inventory_stocks', 'ingredients.ingredient_id', '=', 'inventory_stocks.ingredient_id')
                ->groupBy('category')
                ->get(),
        ]);
    }

    public function history(Request $request, $type, $id)
    {
        if (in_array($type, ['product', 'ingredient'], true)) {
            return $this->ok(InventoryMovement::with('ingredient')->where('ingredient_id', $id)->latest('movement_id')->get());
        }
        return $this->ok(BookingEquipment::with(['booking', 'equipment'])->where('equipment_id', $id)->latest('booking_equipment_id')->get());
    }

    // ==================== EQUIPMENT CHECK-IN/OUT METHODS ====================

    /**
     * Get equipment tracking records with filters
     */
    public function equipmentTracking(Request $request)
{
    $query = BookingEquipment::with([
        'equipment',
        'booking.serviceEvent.customer.person',
    ]);

    if ($request->filled('date')) {
        $date = $request->input('date');
        $query->whereDate('rental_start_date', '<=', $date)
            ->whereDate('rental_end_date', '>=', $date);
    }

    if ($request->filled('status')) {
        $query->where('status', $request->input('status'));
    }

    if ($request->filled('equipment_id')) {
        $query->where('equipment_id', $request->input('equipment_id'));
    }

    if ($request->filled('booking_id')) {
        $query->where('booking_id', $request->input('booking_id'));
    }

    return $this->ok($query->latest('booking_equipment_id')->paginate($request->integer('per_page', 20)));
}
    /**
     * Get equipment availability for a specific date
     */
    public function equipmentAvailability(Request $request)
{
    $request->validate([
        'equipment_id' => 'required|exists:equipment,equipment_id',
        'date' => 'required|date',
    ]);

    $equipmentId = (int) $request->input('equipment_id');
    $date = $request->input('date');

    $total = Equipment::where('equipment_id', $equipmentId)->value('total_quantity') ?? 0;

    $checkedOut = BookingEquipment::where('equipment_id', $equipmentId)
        ->whereIn('status', ['reserved', 'checked_out'])
        ->whereDate('rental_start_date', '<=', $date)
        ->whereDate('rental_end_date', '>=', $date)
        ->sum('quantity_reserved');

    $available = max(0, $total - $checkedOut);

    return $this->ok([
        'equipment_id' => $equipmentId,
        'total' => $total,
        'reserved' => (int) $checkedOut,
        'checked_out' => (int) $checkedOut,
        'available' => $available,
        'is_available' => $available > 0,
        'date' => $date,
    ]);
}

    /**
     * Get all equipment availability for a date
     */
  public function equipmentAvailabilityAll(Request $request)
{
    $request->validate([
        'date' => 'required|date',
    ]);

    $date = $request->input('date');
    $equipment = Equipment::where('is_active', true)->get();

    $result = $equipment->map(function ($item) use ($date) {
        $checkedOut = BookingEquipment::where('equipment_id', $item->equipment_id)
            ->whereIn('status', ['reserved', 'checked_out'])
            ->whereDate('rental_start_date', '<=', $date)
            ->whereDate('rental_end_date', '>=', $date)
            ->sum('quantity_reserved');

        $total = (int) $item->total_quantity;
        $available = max(0, $total - (int) $checkedOut);

        return [
            'equipment_id' => $item->equipment_id,
            'name' => $item->name,
            'category' => $item->category,
            'total' => $total,
            'checked_out' => (int) $checkedOut,
            'available' => $available,
            'is_available' => $available > 0,
            'condition' => $item->condition,
        ];
    });

    return $this->ok($result);
}
    /**
     * Checkout equipment (create tracking record in booking_equipment)
     */
   public function equipmentCheckout(Request $request)
{
    $data = $request->validate([
        'equipment_id' => 'required|exists:equipment,equipment_id',
        'booking_id' => 'nullable|exists:bookings,booking_id',
        'quantity' => 'required|integer|min:1',
        'expected_return_date' => 'required|date|after_or_equal:today',
        'condition_notes_out' => 'nullable|string',
        'notes' => 'nullable|string',
    ]);

    $equipment = Equipment::findOrFail($data['equipment_id']);
    $booking = $data['booking_id'] ? Booking::find($data['booking_id']) : null;

    // Check availability for the date range
    $available = $this->getAvailableQuantity($data['equipment_id'], $data['expected_return_date']);
    
    if ($available < $data['quantity']) {
        return response()->json([
            'success' => false,
            'message' => "Not enough equipment available. Available: {$available}, Requested: {$data['quantity']}",
        ], 422);
    }

    // Equipment availability is derived from booking_equipment rows.

    // Create tracking record
    $tracking = BookingEquipment::create([
        'equipment_id' => $data['equipment_id'],
        'booking_id' => $data['booking_id'],
        'quantity_reserved' => $data['quantity'],
        'quantity_used' => 0,
        'quantity_damaged' => 0,
        'quantity_missing' => 0,
        'rental_start_date' => now(),
        'rental_end_date' => $data['expected_return_date'],
        'rental_price_at_booking' => 0,
        'checked_out_date' => now(),
        'condition_notes_out' => $data['condition_notes_out'] ?? null,
        'notes' => $data['notes'] ?? null,
        'status' => 'checked_out',
    ]);

    return $this->ok(
        $tracking->load(['equipment', 'booking.serviceEvent.customer.person']),
        'Equipment checked out successfully.'
    );
}


    /**
     * Check-in equipment (return)
     */
 public function equipmentCheckIn(Request $request, BookingEquipment $tracking)
{
    $data = $request->validate([
        'quantity_used' => 'nullable|integer|min:0',
        'quantity_damaged' => 'nullable|integer|min:0',
        'quantity_missing' => 'nullable|integer|min:0',
        'condition_notes_in' => 'nullable|string',
        'notes' => 'nullable|string',
    ]);

    if ($tracking->status === 'returned') {
        return response()->json([
            'success' => false,
            'message' => 'This equipment has already been returned.',
        ], 422);
    }

    $quantityReturned = ($data['quantity_used'] ?? 0) + ($data['quantity_damaged'] ?? 0) + ($data['quantity_missing'] ?? 0);
    
    // Equipment availability is derived from booking_equipment rows; no equipment counters are updated here.
    
    // Update tracking record
    $tracking->update([
        'quantity_used' => $data['quantity_used'] ?? 0,
        'quantity_damaged' => $data['quantity_damaged'] ?? 0,
        'quantity_missing' => $data['quantity_missing'] ?? 0,
        'checked_in_date' => now(),
        'condition_notes_in' => $data['condition_notes_in'] ?? null,
        'notes' => $data['notes'] ?? ($tracking->notes ? $tracking->notes . "\n" : '') . "Returned: " . ($data['notes'] ?? ''),
        'status' => $quantityReturned < $tracking->quantity_reserved ? 'damaged' : 'returned',
    ]);

    return $this->ok(
        $this->formatReservation($tracking->load(['equipment', 'booking.serviceEvent.customer.person', 'booking.serviceEvent.eventType'])),
        'Equipment checked in successfully.'
    );
}

    /**
     * Update tracking record (edit quantity or return date)
     */
   public function updateTracking(Request $request, BookingEquipment $tracking)
{
    $data = $request->validate([
        'quantity_reserved' => 'nullable|integer|min:1',
        'rental_end_date' => 'nullable|date',
        'notes' => 'nullable|string',
    ]);

    if ($tracking->status === 'returned') {
        return response()->json([
            'success' => false,
            'message' => 'Cannot update returned equipment.',
        ], 422);
    }

    // Quantity changes are saved on booking_equipment only; equipment counters are computed.


    $tracking->update($data);

    return $this->ok(
        $this->formatReservation($tracking->load(['equipment', 'booking.serviceEvent.customer.person', 'booking.serviceEvent.eventType'])),
        'Tracking record updated successfully.'
    );
}

    /**
     * Get equipment summary with checked out counts
     */
    public function equipmentSummary(Request $request)
{
    $date = $request->input('date', now()->toDateString());
    
    $total = Equipment::sum('total_quantity');
    $checkedOut = BookingEquipment::whereIn('status', ['checked_out'])
        ->whereDate('rental_start_date', '<=', $date)
        ->whereDate('rental_end_date', '>=', $date)
        ->sum('quantity_reserved');

    $reserved = BookingEquipment::where('status', 'reserved')
        ->whereDate('rental_start_date', '<=', $date)
        ->whereDate('rental_end_date', '>=', $date)
        ->sum('quantity_reserved');

    return $this->ok([
        'total_equipment' => (int) $total,
        'reserved' => (int) $reserved,
        'checked_out' => (int) $checkedOut,
        'available' => max(0, (int) $total - (int) $checkedOut - (int) $reserved),
        'date' => $date,
        'by_category' => Equipment::select('category')
            ->selectRaw('SUM(total_quantity) as total')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('category')
            ->get(),
    ]);
}
    /**
     * Get available quantity helper
     */
   private function getAvailableQuantity(int $equipmentId, string $endDate): int
{
    $total = Equipment::where('equipment_id', $equipmentId)->value('total_quantity') ?? 0;
    
    $checkedOut = BookingEquipment::where('equipment_id', $equipmentId)
        ->whereIn('status', ['reserved', 'checked_out'])
        ->whereDate('rental_start_date', '<=', now())
        ->whereDate('rental_end_date', '>=', now())
        ->whereDate('rental_end_date', '<=', $endDate)
        ->sum('quantity_reserved');

    return max(0, $total - $checkedOut);
}
    private function formatMovement(InventoryMovement $movement): array
    {
        [$source, $reference] = $this->resolveMovementSource($movement);

        $type = match ($movement->movement_type) {
            'purchase', 'return', 'restock', 'stock_in' => 'stock_in',
            'usage', 'waste', 'stock_out' => 'stock_out',
            'adjustment' => 'manual_adjustment',
            default => $movement->movement_type,
        };
        $user = $movement->performedBy;

        return [
            'id' => 'ING-' . $movement->movement_id,
            'movement_id' => $movement->movement_id,
            'item_type' => 'ingredient',
            'item_id' => $movement->ingredient_id,
            'item_name' => $movement->ingredient?->name ?? 'Unknown ingredient',
            'product_name' => $movement->ingredient?->name ?? 'Unknown ingredient',
            'unit' => $movement->ingredient?->unit,
            'movement_type' => $type,
            'raw_movement_type' => $movement->movement_type,
            'quantity' => abs((float) $movement->quantity_change),
            'quantity_change' => (float) $movement->quantity_change,
            'quantity_before' => (float) $movement->quantity_before,
            'quantity_after' => (float) $movement->quantity_after,
            'reason' => $movement->reason,
            'source' => $source,
            'reference' => $reference,
            'reference_type' => $movement->reference_type,
            'reference_id' => $movement->reference_id,
            'updated_by' => $user?->person?->full_name ?? $user?->email ?? 'System',
            'performed_by' => $movement->performed_by,
            'movement_at' => optional($movement->created_at)->toISOString(),
            'created_at' => $movement->created_at,
        ];
    }

    private function resolveMovementSource(InventoryMovement $movement): array
    {
        $type = $movement->movement_type;
        $reason = strtolower((string) $movement->reason);
        $refType = $movement->reference_type;

        if ($type === 'waste' || str_contains($reason, 'waste')) {
            return ['Waste Management', $refType ? "{$refType} #{$movement->reference_id}" : 'Waste Record'];
        }

        if ($type === 'usage' && ($refType === 'Booking' || str_contains($reason, 'booking'))) {
            return ['Order', $refType ? "{$refType} #{$movement->reference_id}" : '—'];
        }

        if ($type === 'purchase' && ($refType === 'PurchaseRequest' || str_contains($reason, 'purchase'))) {
            return ['Purchase', $refType ? "{$refType} #{$movement->reference_id}" : '—'];
        }

        if (str_contains($reason, 'ingredient_created') || str_contains($reason, 'ingredient_updated') || str_contains($reason, 'stock_update')) {
            return ['Ingredients Management', '—'];
        }

        if (str_contains($reason, 'auto_reorder')) {
            return ['Auto Reorder', '—'];
        }

        if ($type === 'return') {
            return ['Return', $refType ? "{$refType} #{$movement->reference_id}" : '—'];
        }

        return [ucfirst($type), $refType ? "{$refType} #{$movement->reference_id}" : '—'];
    }

    private function formatEquipmentMovement(BookingEquipment $tracking): array
    {
        $isReturned = in_array($tracking->status, ['returned', 'damaged', 'missing'], true) || $tracking->checked_in_date;

        return [
            'id' => 'EQ-' . $tracking->booking_equipment_id,
            'movement_id' => $tracking->booking_equipment_id,
            'item_type' => 'equipment',
            'item_id' => $tracking->equipment_id,
            'item_name' => $tracking->equipment?->name ?? 'Unknown equipment',
            'product_name' => $tracking->equipment?->name ?? 'Unknown equipment',
            'unit' => 'pcs',
            'movement_type' => $isReturned ? 'return' : 'reservation',
            'raw_movement_type' => $tracking->status,
            'quantity' => (int) $tracking->quantity_reserved,
            'quantity_change' => $isReturned ? (int) $tracking->quantity_reserved : -(int) $tracking->quantity_reserved,
            'reason' => $tracking->notes,
            'reference_type' => 'BookingEquipment',
            'reference_id' => $tracking->booking_id,
            'reference' => $tracking->booking?->booking_no,
            'updated_by' => 'System',
            'movement_at' => optional($tracking->updated_at ?? $tracking->created_at)->toISOString(),
            'created_at' => $tracking->created_at,
        ];
    }

    private function formatWaste(WasteRecord $record): array
    {
        return [
            'id' => $record->waste_record_id,
            'waste_record_id' => $record->waste_record_id,
            'ingredient_id' => $record->ingredient_id,
            'ingredient' => $record->ingredient?->name,
            'ingredient_name' => $record->ingredient?->name,
            'product_name' => $record->ingredient?->name,
            'quantity' => (float) $record->quantity,
            'unit' => $record->ingredient?->unit,
            'reason' => $record->reason,
            'waste_type' => $record->reason,
            'notes' => $record->notes,
            'cost' => (float) $record->quantity * (float) ($record->ingredient?->unit_cost ?? 0),
            'recorded_by' => $record->recorder?->person?->full_name ?? $record->recorder?->email ?? 'System',
            'date' => optional($record->created_at)->toDateString(),
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
        ];
    }

    private function formatPurchaseRequest(PurchaseRequest $purchaseRequest): array
    {
        $booking = $purchaseRequest->booking;
        $event = $booking?->serviceEvent;

        return [
            'id' => $purchaseRequest->purchase_request_id,
            'purchase_request_id' => $purchaseRequest->purchase_request_id,
            'request_id' => $purchaseRequest->pr_number,
            'pr_number' => $purchaseRequest->pr_number,
            'ingredient_id' => $purchaseRequest->ingredient_id,
            'ingredient' => $purchaseRequest->ingredient?->name,
            'ingredient_name' => $purchaseRequest->ingredient?->name,
            'product_name' => $purchaseRequest->ingredient?->name,
            'quantity' => (float) $purchaseRequest->quantity,
            'unit' => $purchaseRequest->ingredient?->unit,
            'supplier_id' => $purchaseRequest->supplier_id,
            'supplier' => $purchaseRequest->supplier?->name,
            'booking_id' => $purchaseRequest->booking_id,
            'booking_no' => $booking?->booking_no,
            'event_name' => $event?->eventType?->name,
            'event_date' => optional($event?->event_date)->toDateString(),
            'customer_name' => $event?->customer?->person?->full_name,
            'urgency' => $purchaseRequest->urgency,
            'status' => $purchaseRequest->status,
            'expected_delivery' => optional($purchaseRequest->expected_delivery)->toDateString(),
            'notes' => $purchaseRequest->notes,
            'requested_by' => $purchaseRequest->requester?->person?->full_name ?? $purchaseRequest->requester?->email ?? 'System',
            'created_at' => $purchaseRequest->created_at,
            'updated_at' => $purchaseRequest->updated_at,
        ];
    }

    private function formatReservation(BookingEquipment $tracking): array
    {
        $event = $tracking->booking?->serviceEvent;

        return [
            'id' => $tracking->booking_equipment_id,
            'booking_equipment_id' => $tracking->booking_equipment_id,
            'tracking_id' => $tracking->booking_equipment_id,
            'equipment_id' => $tracking->equipment_id,
            'equipment' => $tracking->equipment?->name,
            'equipment_name' => $tracking->equipment?->name,
            'booking_id' => $tracking->booking_id,
            'booking_no' => $tracking->booking?->booking_no,
            'event_booking' => $tracking->booking_id,
            'customer_name' => $event?->customer?->person?->full_name,
            'event_name' => $event?->eventType?->name,
            'event_date' => optional($event?->event_date)->toDateString(),
            'quantity' => (int) $tracking->quantity_reserved,
            'quantity_reserved' => (int) $tracking->quantity_reserved,
            'unit' => 'pcs',
            'status' => $tracking->status,
            'rental_start_date' => optional($tracking->rental_start_date)->toDateString(),
            'rental_end_date' => optional($tracking->rental_end_date)->toDateString(),
            'expected_return' => optional($tracking->rental_end_date)->toDateString(),
            'checked_out_date' => optional($tracking->checked_out_date)->toISOString(),
            'checked_in_date' => optional($tracking->checked_in_date)->toISOString(),
            'quantity_used' => (int) $tracking->quantity_used,
            'quantity_damaged' => (int) $tracking->quantity_damaged,
            'quantity_missing' => (int) $tracking->quantity_missing,
            'notes' => $tracking->notes,
            'created_at' => $tracking->created_at,
            'updated_at' => $tracking->updated_at,
        ];
    }

    private function validateMaintenance(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'equipment_id' => [$required, 'exists:equipment,equipment_id'],
            'assigned_to' => ['nullable', 'string', 'max:255'],
            'type' => [$required, Rule::in(['preventive', 'corrective', 'inspection', 'cleaning', 'calibration', 'other'])],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'duration' => ['nullable', 'numeric', 'min:0'],
            'scheduled_date' => [$required, 'date'],
            'completed_date' => ['nullable', 'date'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['scheduled', 'in_progress', 'completed', 'cancelled'])],
        ]);
    }

    private function formatMaintenance(AuditLog $record): array
    {
        $payload = (array) $record->new_values;
        $equipment = ! empty($payload['equipment_id']) ? Equipment::find($payload['equipment_id']) : null;

        return array_merge($payload, [
            'id' => $record->audit_id,
            'maintenance_id' => $record->audit_id,
            'equipment' => $equipment?->name,
            'equipment_name' => $equipment?->name,
            'status' => $payload['status'] ?? 'scheduled',
            'priority' => $payload['priority'] ?? 'medium',
            'updated_by' => $record->user?->person?->full_name ?? $record->user?->email ?? 'System',
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
        ]);
    }

    private function eventDateTime(Booking $booking): ?Carbon
    {
        $event = $booking->serviceEvent;
        if (! $event?->event_date) {
            return null;
        }

        $date = $event->event_date instanceof Carbon
            ? $event->event_date->toDateString()
            : Carbon::parse($event->event_date)->toDateString();
        $time = $event->event_time ?: '00:00:00';

        return Carbon::parse(trim($date . ' ' . $time));
    }

    /**
     * ⭐ List all approved requests (for the Approved Requests page).
     */
    public function approvedRequests(Request $request)
    {
        $query = PurchaseRequest::with([
            'ingredient',
            'supplier',
            'requester.person',
            'booking.serviceEvent.customer.person',
            'booking.serviceEvent.eventType',
        ])->where('status', 'approved');

        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->input('date'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($builder) use ($search) {
                $builder->where('pr_number', 'like', "%{$search}%")
                    ->orWhereHas('ingredient', fn ($i) => $i->where('name', 'like', "%{$search}%"));
            });
        }

        $rows = $query->latest('purchase_request_id')
            ->paginate(min(max($request->integer('per_page', 50), 1), 500));

        $rows->getCollection()->transform(fn (PurchaseRequest $pr) => $this->formatPurchaseRequest($pr));

        return $this->ok($rows);
    }

    /**
     * ⭐ Group approved requests by date into "request files".
     */
    public function approvedRequestFiles(Request $request)
    {
        $rows = PurchaseRequest::with(['ingredient'])
            ->where('status', 'approved')
            ->orderBy('created_at')
            ->get();

        $grouped = $rows->groupBy(fn ($pr) => optional($pr->created_at)->toDateString());

        // ⭐ Read custom file names from settings so admin edits persist.
        $customNames = \App\Models\Setting::getValue('inventory', 'approved_request_file_names', []);
        if (is_string($customNames)) {
            $customNames = json_decode($customNames, true) ?: [];
        }

        $files = $grouped->map(function ($items, $date) use ($customNames) {
            $defaultName = \Carbon\Carbon::parse($date)->format('F j, Y') . ' Request';
            return [
                'date' => $date,
                'name' => $customNames[$date] ?? $defaultName,
                'request_count' => $items->count(),
                'total_quantity' => (float) $items->sum('quantity'),
                'requests' => $items->map(fn ($pr) => $this->formatPurchaseRequest($pr))->values(),
            ];
        })->values();

        return $this->ok($files);
    }

    /**
     * ⭐ Rename an approved request file/group.
     */
    public function renameApprovedRequestFile(Request $request, string $date)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $customNames = \App\Models\Setting::getValue('inventory', 'approved_request_file_names', []);
        if (is_string($customNames)) {
            $customNames = json_decode($customNames, true) ?: [];
        }
        if (! is_array($customNames)) {
            $customNames = [];
        }

        $customNames[$date] = $validated['name'];

        \App\Models\Setting::setValue('inventory', 'approved_request_file_names', $customNames, 'json');

        return $this->ok(['date' => $date, 'name' => $validated['name']], 'Request file renamed.');
    }
}