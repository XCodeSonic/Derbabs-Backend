<?php

namespace App\Http\Controllers\Api;

use App\Models\BookingEquipment;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\Setting;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderController extends Controller
{
    private function query()
    {
        return Order::with($this->orderRelations());
    }

    private function orderRelations(): array
    {
        $relations = [
            'booking.serviceEvent.customer.person',
            'booking.serviceEvent.eventType',
            'booking.serviceEvent.package.menuItems.category',
            'booking.invoice',
            'booking.payments',
            'booking.items.menuItem',
            'items.menuItem',
        ];

        if (Schema::hasTable('meal_services') && Schema::hasTable('order_items') && Schema::hasColumn('order_items', 'meal_service_id')) {
            $relations[] = Schema::hasTable('event_days') ? 'items.mealService.eventDay' : 'items.mealService';
        }

        return $relations;
    }

    public function index(Request $request)
    {
        $query = $this->query();
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('booking_id')) {
            $query->where('booking_id', $request->input('booking_id'));
        }
        if ($request->filled('event_date')) {
            $query->whereHas('booking.serviceEvent', fn ($event) => $event
                ->whereDate('event_date', '<=', $request->input('event_date'))
                ->whereRaw('COALESCE(event_end_date, event_date) >= ?', [$request->input('event_date')]));
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($builder) use ($search) {
                $builder->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('booking', fn ($booking) => $booking->where('booking_no', 'like', "%{$search}%"))
                    ->orWhereHas('booking.serviceEvent.customer.person', fn ($person) => $person
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%"));
            });
        }
        $rows = $query->latest('order_id')->paginate($request->integer('per_page', 20));
        $rows->getCollection()->transform(fn (Order $order) => $this->formatOrder($order));
        return $this->ok($rows);
    }

    public function show(Order $order)
    {
        return $this->ok($this->formatOrder($this->query()->findOrFail($order->order_id)));
    }

    public function store(Request $request)
    {
        return response()->json(['success' => false, 'message' => 'Orders are created automatically when a booking is confirmed.'], 422);
    }

    public function update(Request $request, Order $order)
    {
        $data = $request->validate(['status' => ['nullable', 'in:pending,preparing,ready,ongoing,completed,cancelled']]);
        $order->update($data);
        return $this->show($order);
    }

    public function destroy(Order $order)
    {
        $order->delete();
        return $this->ok(null, 'Order archived.');
    }

    public function updateStatus(Request $request, Order $order)
    {
        $data = $request->validate(['status' => ['required', 'in:pending,preparing,ready,ongoing,completed,cancelled']]);
        $order->update($data);
        return $this->ok($this->formatOrder($order->fresh()), 'Order status updated.');
    }

    public function kitchen(Order $order)
    {
        $tasks = $this->kitchenTasks($order);
        $this->storeKitchenTasks($order, $tasks);
        $order->update(['status' => $order->status === 'pending' ? 'preparing' : $order->status]);
        return $this->ok($this->formatOrder($order->fresh()), 'Kitchen preparation is ready for this booking.');
    }

    public function removeFromKitchen(Order $order)
    {
        $metadata = $this->metadata($order);
        unset($metadata['kitchen_preparation']);
        $this->replaceMetadata($order, $metadata);
        Setting::where('group', 'kitchen_tasks')->where('key', 'order_' . $order->order_id)->delete();
        return $this->ok($this->formatOrder($order->fresh()), 'Removed from kitchen preparation.');
    }

    public function delivery(Order $order)
    {
        $items = $this->deliveryItems($order);
        $this->storeDeliveryItems($order, $items);
        return $this->ok($this->formatOrder($order->fresh()), 'Delivery preparation is ready for this booking.');
    }

    public function removeFromDelivery(Order $order)
    {
        $metadata = $this->metadata($order);
        unset($metadata['delivery_preparation']);
        $this->replaceMetadata($order, $metadata);
        Setting::where('group', 'delivery_items')->where('key', 'order_' . $order->order_id)->delete();
        return $this->ok($this->formatOrder($order->fresh()), 'Removed from delivery preparation.');
    }

    public function getKitchenTasks(Order $order)
    {
        return $this->ok($this->kitchenTasks($order));
    }

    public function updateKitchenTasks(Request $request, Order $order)
    {
        $tasks = $this->normalizeTasks($request->input('tasks', $request->all()));
        $this->storeKitchenTasks($order, $tasks);
        return $this->ok(['order' => $this->formatOrder($order->fresh()), 'tasks' => $tasks], 'Kitchen tasks updated.');
    }

    public function updateKitchenTask(Request $request, Order $order)
    {
        if ($request->has('tasks')) {
            return $this->updateKitchenTasks($request, $order);
        }

        $tasks = $this->kitchenTasks($order);
        $index = $this->findItemIndex($tasks, $request->input('task_id'), $request->input('task_index'));
        if ($index === null) {
            return response()->json(['success' => false, 'message' => 'Kitchen task not found.'], 422);
        }

        $updates = $request->only(['status', 'notes', 'prepare_time', 'start_time', 'end_time', 'assigned_to', 'out_for_delivery', 'is_done']);
        if ($request->has('is_done')) {
            $updates['status'] = $request->boolean('is_done') ? 'completed' : 'pending';
        }
        $tasks[$index] = array_merge($tasks[$index], $updates, ['updated_at' => now()->toIso8601String()]);
        $this->storeKitchenTasks($order, $tasks);
        return $this->ok(['order' => $this->formatOrder($order->fresh()), 'task' => $tasks[$index]], 'Kitchen task updated.');
    }

    public function getDeliveryItems(Order $order)
    {
        return $this->ok($this->deliveryItems($order));
    }

    public function updateDeliveryItems(Request $request, Order $order)
    {
        $items = $this->normalizeDeliveryItems($request->input('items', $request->all()));
        $this->storeDeliveryItems($order, $items);
        return $this->ok(['order' => $this->formatOrder($order->fresh()), 'items' => $items], 'Delivery preparation updated.');
    }

    public function updateDeliveryItem(Request $request, Order $order)
    {
        if ($request->has('items')) {
            return $this->updateDeliveryItems($request, $order);
        }

        $items = $this->deliveryItems($order);
        $index = $this->findItemIndex($items, $request->input('item_id'), $request->input('item_index'));
        if ($index === null) {
            return response()->json(['success' => false, 'message' => 'Delivery preparation item not found.'], 422);
        }

        $updates = $request->only(['status', 'notes', 'delivery_time', 'scheduled_time', 'quantity', 'item', 'equipment_id', 'is_ready']);
        if ($request->has('is_ready')) {
            $updates['status'] = $request->boolean('is_ready') ? 'ready' : 'pending';
        }
        $items[$index] = array_merge($items[$index], $updates, ['updated_at' => now()->toIso8601String()]);
        $this->storeDeliveryItems($order, $items);
        return $this->ok(['order' => $this->formatOrder($order->fresh()), 'delivery' => $items[$index]], 'Delivery item updated.');
    }

    public function addDeliveryItem(Request $request, Order $order)
    {
        $data = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.equipment_id' => ['required_with:items', 'integer', 'exists:equipment,equipment_id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'equipment_id' => ['nullable', 'integer', 'exists:equipment,equipment_id'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ]);

        $selections = $data['items'] ?? [];
        if (empty($selections) && !empty($data['equipment_id'])) {
            $selections[] = [
                'equipment_id' => $data['equipment_id'],
                'quantity' => (int) ($data['quantity'] ?? 1),
                'notes' => $data['notes'] ?? null,
            ];
        }

        if (empty($selections)) {
            return response()->json(['success' => false, 'message' => 'Please select at least one equipment item.'], 422);
        }

        return DB::transaction(function () use ($order, $selections) {
            $order->loadMissing('booking.serviceEvent');
            $booking = $order->booking;
            $event = $booking?->serviceEvent;
            $startDate = $event?->event_date?->toDateString() ?? now()->toDateString();
            $endDate = ($event?->event_end_date ?? $event?->event_date)?->toDateString() ?? $startDate;
            $items = $this->deliveryItems($order);
            $added = [];

            foreach ($selections as $selection) {
                $equipment = Equipment::findOrFail((int) $selection['equipment_id']);
                $quantity = max(1, (int) ($selection['quantity'] ?? 1));
                $available = $this->equipmentAvailableForDate($equipment, $startDate, $endDate, $booking?->booking_id);

                if ($available < $quantity) {
                    return response()->json([
                        'success' => false,
                        'message' => "Requested quantity exceeds the available equipment stock. Available: {$available}. Requested: {$quantity}.",
                    ], 422);
                }

                if ($booking) {
                    BookingEquipment::updateOrCreate(
                        ['booking_id' => $booking->booking_id, 'equipment_id' => $equipment->equipment_id],
                        [
                            'quantity_reserved' => $quantity,
                            'rental_start_date' => $startDate,
                            'rental_end_date' => $endDate,
                            'rental_price_at_booking' => $equipment->rental_price ?? 0,
                            'status' => 'reserved',
                        ]
                    );
                }

                $item = [
                    'id' => 'eq-' . $equipment->equipment_id . '-' . now()->timestamp,
                    'equipment_id' => $equipment->equipment_id,
                    'item' => $equipment->name,
                    'quantity' => $quantity,
                    'status' => 'pending',
                    'is_ready' => false,
                    'notes' => $selection['notes'] ?? null,
                    'available_on_event_date' => $available - $quantity,
                    'added_at' => now()->toIso8601String(),
                ];
                $items[] = $item;
                $added[] = $item;
            }

            $this->storeDeliveryItems($order, $items);
            return $this->ok(['order' => $this->formatOrder($order->fresh()), 'items' => $items, 'added' => $added], 'Delivery item saved.');
        });
    }

    public function ingredients(Order $order, InventoryService $service)
    {
        $booking = $order->booking;
        if (! $booking) {
            return $this->ok([], 'No booking found for this order.');
        }
        $requirements = $service->getIngredientRequirements($booking);
        return $this->ok([
            'order_id' => $order->order_id,
            'order_number' => $order->order_number,
            'customer_name' => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
            'event_name' => $booking->serviceEvent?->eventType?->name ?? 'Event',
            'event_date' => $booking->serviceEvent?->event_date?->toDateString(),
            'guests_count' => (int) ($booking->serviceEvent?->guests_count ?? 0),
            'ingredients' => $requirements,
            'summary' => [
                'total_ingredients' => count($requirements),
                'total_need_to_buy' => collect($requirements)->where('need_to_buy', true)->count(),
                'total_shortage' => (float) collect($requirements)->sum('shortage'),
            ],
        ]);
    }

    public function computeIngredients(Order $order, InventoryService $service)
    {
        $this->updateMetadata($order, ['ingredients_computed_flag' => true, 'ingredients_computed_at' => now()->toIso8601String()]);
        return $this->ingredients($order, $service);
    }

    public function stats()
    {
        $total = Order::count();
        $completed = Order::where('status', 'completed')->count();
        return $this->ok([
            'total' => $total,
            'total_orders' => $total,
            'total_revenue' => (float) Order::with('booking.invoice')->get()->sum(fn ($order) => (float) ($order->booking?->invoice?->total_amount ?? 0)),
            'pending' => Order::where('status', 'pending')->count(),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'preparing' => Order::where('status', 'preparing')->count(),
            'preparing_orders' => Order::where('status', 'preparing')->count(),
            'ready' => Order::where('status', 'ready')->count(),
            'ready_orders' => Order::where('status', 'ready')->count(),
            'ongoing' => Order::where('status', 'ongoing')->count(),
            'ongoing_orders' => Order::where('status', 'ongoing')->count(),
            'completed' => $completed,
            'completed_orders' => $completed,
            'cancelled' => Order::where('status', 'cancelled')->count(),
            'cancelled_orders' => Order::where('status', 'cancelled')->count(),
            'pending_purchases' => \App\Models\PurchaseRequest::where('status', 'pending')->count(),
            'kitchen_orders' => $this->activePreparationQuery()->count(),
            'delivery_orders' => $this->activePreparationQuery()->count(),
        ]);
    }

    public function kitchenOrders()
    {
        return $this->ok($this->activePreparationQuery()->latest('order_id')->get()->map(fn (Order $order) => $this->formatOrder($order))->values());
    }

    public function deliveryOrders()
    {
        return $this->ok($this->activePreparationQuery()->latest('order_id')->get()->map(fn (Order $order) => $this->formatOrder($order))->values());
    }

    private function activePreparationQuery()
    {
        return $this->query()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereHas('booking', fn ($booking) => $booking->whereNotIn('booking_status', ['completed', 'cancelled', 'rejected']));
    }

    private function formatOrder(Order $order): array
    {
        $order->loadMissing($this->orderRelations());
        $booking = $order->booking;
        $event = $booking?->serviceEvent;
        $person = $event?->customer?->person;
        $metadata = $this->metadata($order);
        $kitchenTasks = $metadata['kitchen_preparation'] ?? $this->legacySetting('kitchen_tasks', $order) ?? $this->defaultKitchenPreparation($order);
        $deliveryItems = $metadata['delivery_preparation'] ?? $this->legacySetting('delivery_items', $order) ?? [];
        $totalAmount = (float) ($booking?->invoice?->total_amount ?? $booking?->quotation?->total_amount ?? 0);
        $paidAmount = (float) ($booking?->payments?->where('status', 'completed')->sum('amount') ?? 0);
        $package = $event?->package;
        $packageMenuItems = $package?->menuItems?->map(function ($item) use ($package) {
            return [
                'menu_item_id' => $item->menu_item_id,
                'name' => $item->name,
                'quantity' => (int) ($item->pivot->quantity_per_pax ?? 1),
                'category' => $item->category?->name,
                'package_id' => $package->package_id,
                'package_name' => $package->name,
                'package_association' => $package->name,
            ];
        })->values() ?? collect();

        return [
            'id' => $order->order_id,
            'order_id' => $order->order_id,
            'order_number' => $order->order_number,
            'booking_id' => $booking?->booking_id,
            'booking_no' => $booking?->booking_no,
            'customer_name' => $person?->full_name ?? trim(($person?->first_name ?? '') . ' ' . ($person?->last_name ?? '')) ?: 'Unknown',
            'customer_email' => $person?->email,
            'customer_phone' => $person?->phone,
            'customer_address' => $person?->address_line_1,
            'event_type' => $event?->eventType?->name,
            'event_date' => $event?->event_date?->toDateString(),
            'event_end_date' => ($event?->event_end_date ?? $event?->event_date)?->toDateString(),
            'event_time' => $event?->event_time,
            'venue' => $event?->venue,
            'guests_count' => (int) ($event?->guests_count ?? 0),
            'delivery_method' => $event?->delivery_method,
            'package_id' => $package?->package_id,
            'package_name' => $package?->name,
            'package_details' => $package ? [
                'package_id' => $package->package_id,
                'name' => $package->name,
                'base_price_per_pax' => (float) $package->base_price_per_pax,
                'menu_items' => $packageMenuItems,
            ] : null,
            'package_menu_items' => $packageMenuItems,
            'delivery_address' => $event?->delivery_address ?? $event?->venue,
            'delivery_contact_person' => $event?->delivery_contact_person,
            'delivery_contact_phone' => $event?->delivery_contact_phone ?? $person?->phone,
            'total_amount' => $totalAmount,
            'paid_amount' => $paidAmount,
            'balance' => max(0, $totalAmount - $paidAmount),
            'payment_status' => $paidAmount <= 0 ? 'pending' : ($paidAmount < $totalAmount ? 'partial' : 'paid'),
            'order_status' => $order->status,
            'status' => $order->status,
            'menu_items' => $order->items->map(function ($item) {
                $meal = $item->relationLoaded('mealService') ? $item->mealService : null;
                return [
                'id' => $item->order_item_id,
                'meal_service_id' => $item->meal_service_id ?? null,
                'meal_type' => $meal?->meal_type,
                'service_date' => $meal?->service_date?->toDateString(),
                'serving_time' => $meal?->serving_time,
                'menu_item_id' => $item->menu_item_id,
                'name' => $item->item_name,
                'quantity' => (int) $item->quantity,
                'price' => (float) $item->unit_price_snapshot,
                'total' => (float) $item->unit_price_snapshot * (int) $item->quantity,
                ];
            })->values(),
            'ingredients_computed_flag' => (bool) ($metadata['ingredients_computed_flag'] ?? false),
            'added_to_kitchen' => true,
            'added_to_delivery' => true,
            'kitchen_preparation' => array_values($kitchenTasks),
            'delivery_preparation' => array_values($deliveryItems),
            'has_unpurchased_ingredients' => $this->bookingHasUnpurchasedIngredients($booking),
        ];
    }

    private function defaultKitchenPreparation(Order $order): array
    {
        $order->loadMissing($this->orderRelations());
        $event = $order->booking?->serviceEvent;

        return $order->items->map(function ($item) use ($event) {
            $meal = $item->relationLoaded('mealService') ? $item->mealService : null;
            return [
                'id' => 'kitchen-' . $item->order_item_id,
                'item_name' => $item->item_name,
                'name' => $item->item_name,
                'task' => $item->item_name,
                'meal_service_id' => $item->meal_service_id ?? null,
                'meal_type' => $meal?->meal_type,
                'day_number' => $meal?->eventDay?->day_number,
                'service_date' => optional($meal?->service_date)->toDateString(),
                'serving_time' => $meal?->serving_time,
                'quantity' => (int) $item->quantity,
                'pax' => (int) ($meal?->pax ?? $event?->guests_count ?? 0),
                'servings' => (int) $item->quantity,
                'event_date' => optional($meal?->service_date)->toDateString() ?? $event?->event_date?->toDateString(),
                'start_time' => $meal?->preparation_time ?? $event?->event_time,
                'out_for_delivery' => $meal?->dispatch_time ?? $event?->scheduled_delivery_time?->toDateTimeString(),
                'assigned_to' => 'Kitchen Team',
                'status' => 'pending',
                'is_done' => false,
                'notes' => null,
            ];
        })->values()->all();
    }

    private function kitchenTasks(Order $order): array
    {
        $metadata = $this->metadata($order);
        $tasks = $metadata['kitchen_preparation'] ?? $this->legacySetting('kitchen_tasks', $order) ?? null;
        if (!$tasks) {
            $tasks = $this->defaultKitchenPreparation($order);
            $this->storeKitchenTasks($order, $tasks);
        }
        return $this->normalizeTasks($tasks);
    }

    private function deliveryItems(Order $order): array
    {
        $metadata = $this->metadata($order);
        $items = $metadata['delivery_preparation'] ?? $this->legacySetting('delivery_items', $order) ?? [];
        return $this->normalizeDeliveryItems($items);
    }

    private function normalizeTasks($tasks): array
    {
        return collect(is_array($tasks) ? $tasks : [])->map(function ($task, $index) {
            $task = is_array($task) ? $task : [];
            $status = $task['status'] ?? (($task['is_done'] ?? false) ? 'completed' : 'pending');
            return array_merge($task, [
                'id' => $task['id'] ?? 'task-' . $index,
                'task' => preg_replace('/^[^\pL\pN]+/u', '', (string) ($task['task'] ?? $task['name'] ?? $task['item_name'] ?? 'Task')),
                'quantity' => (int) ($task['quantity'] ?? 1),
                'servings' => (int) ($task['servings'] ?? $task['pax'] ?? $task['quantity'] ?? 1),
                'start_time' => $task['start_time'] ?? $task['prepare_time'] ?? '-',
                'out_for_delivery' => $task['out_for_delivery'] ?? $task['delivery_time'] ?? $task['end_time'] ?? '-',
                'assigned_to' => $task['assigned_to'] ?? 'Kitchen Team',
                'status' => $status,
                'is_done' => ($task['is_done'] ?? false) || $status === 'completed',
            ]);
        })->values()->all();
    }

    private function normalizeDeliveryItems($items): array
    {
        return collect(is_array($items) ? $items : [])->map(function ($item, $index) {
            $item = is_array($item) ? $item : [];
            $status = $item['status'] ?? (($item['is_ready'] ?? false) ? 'ready' : 'pending');
            return array_merge($item, [
                'id' => $item['id'] ?? 'delivery-' . $index,
                'item' => preg_replace('/^[^\pL\pN]+/u', '', (string) ($item['item'] ?? $item['name'] ?? 'Equipment Item')),
                'quantity' => (int) ($item['quantity'] ?? 1),
                'status' => $status,
                'is_ready' => ($item['is_ready'] ?? false) || in_array($status, ['ready', 'delivered', 'completed'], true),
            ]);
        })->values()->all();
    }

    private function storeKitchenTasks(Order $order, array $tasks): void
    {
        $this->updateMetadata($order, ['kitchen_preparation' => array_values($tasks)]);
        Setting::updateOrCreate(
            ['group' => 'kitchen_tasks', 'key' => 'order_' . $order->order_id],
            ['value' => json_encode(array_values($tasks), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'type' => 'json']
        );
    }

    private function storeDeliveryItems(Order $order, array $items): void
    {
        $this->updateMetadata($order, ['delivery_preparation' => array_values($items)]);
        Setting::updateOrCreate(
            ['group' => 'delivery_items', 'key' => 'order_' . $order->order_id],
            ['value' => json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'type' => 'json']
        );
    }

    private function findItemIndex(array $items, $id = null, $index = null): ?int
    {
        if ($index !== null && isset($items[(int) $index])) {
            return (int) $index;
        }
        if ($id !== null) {
            foreach ($items as $i => $item) {
                if (($item['id'] ?? null) === $id) {
                    return $i;
                }
            }
        }
        return isset($items[0]) ? 0 : null;
    }

    private function legacySetting(string $group, Order $order): ?array
    {
        $setting = Setting::where('group', $group)->where('key', 'order_' . $order->order_id)->first();
        if (!$setting) {
            return null;
        }
        $value = $this->decodeSettingValue($setting->value);
        return is_array($value) ? $value : null;
    }

    private function metadata(Order $order): array
    {
        $setting = Setting::where('group', 'order_metadata')->where('key', 'order_' . $order->order_id)->first();
        return $setting ? $this->decodeSettingValue($setting->value) : [];
    }

    private function updateMetadata(Order $order, array $changes): array
    {
        return DB::transaction(function () use ($order, $changes) {
            $metadata = array_merge($this->metadata($order), $changes);
            $this->replaceMetadata($order, $metadata);
            return $metadata;
        });
    }

    private function replaceMetadata(Order $order, array $metadata): void
    {
        Setting::updateOrCreate(
            ['group' => 'order_metadata', 'key' => 'order_' . $order->order_id],
            ['value' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'type' => 'json']
        );
    }

    private function decodeSettingValue($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function equipmentAvailableForDate(Equipment $equipment, string $startDate, string $endDate, ?int $currentBookingId = null): int
    {
        $query = BookingEquipment::where('equipment_id', $equipment->equipment_id)
            ->whereIn('status', ['reserved', 'checked_out'])
            ->where(function ($builder) use ($startDate, $endDate) {
                $builder->whereDate('rental_start_date', '<=', $endDate)
                    ->whereDate('rental_end_date', '>=', $startDate);
            });

        if ($currentBookingId) {
            $query->where('booking_id', '!=', $currentBookingId);
        }

        $reserved = (int) $query->sum('quantity_reserved');
        return max(0, (int) $equipment->total_quantity - $reserved);
    }

    private function bookingHasUnpurchasedIngredients($booking): bool
    {
        if (!$booking) {
            return false;
        }
        $setting = Setting::where('group', 'ingredients_summary')->where('key', 'booking_' . $booking->booking_id)->first();
        $ingredients = $setting ? $this->decodeSettingValue($setting->value) : [];
        return collect($ingredients)->contains(fn ($ingredient) => ($ingredient['need_to_buy'] ?? false) && !($ingredient['purchased'] ?? false));
    }
}
