<?php
// app/Services/ProfitabilityService.php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingCostSnapshot;
use App\Models\Ingredient;
use App\Models\InventoryStock;
use App\Models\MenuItem;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProfitabilityService
{
    /**
     * Calculate ingredient cost from recipe-based requirements.
     * Uses existing recipe logic: (quantity_per_pax * pax) + buffer
     */
    public function calculateIngredientCost(Booking $booking, bool $useHistoricalCost = false): array
    {
        $booking->loadMissing([
            'items.menuItem.recipeIngredients.ingredient',
            'mealServices.menuItem.recipeIngredients.ingredient',
            'serviceEvent',
        ]);

        $breakdown = [];
        $totalCost = 0.0;

        // Get pax count
        $pax = (int) ($booking->serviceEvent?->guests_count ?? 0);
        if ($pax <= 0) {
            $pax = (int) $booking->mealServices->sum('pax');
        }

        // Collect all menu items and their quantities
        $menuItems = collect();

        // From booking items
        foreach ($booking->items as $item) {
            if (!$item->menu_item_id) continue;
            $menuItem = $item->menuItem;
            if (!$menuItem) continue;

            $quantity = (int) ($item->quantity ?? $pax);
            $menuItems->push([
                'menu_item' => $menuItem,
                'quantity' => $quantity,
                'meal_type' => $item->mealService?->meal_type,
                'service_date' => $item->mealService?->service_date?->toDateString(),
            ]);
        }

        // From meal services (if no items exist)
        if ($menuItems->isEmpty()) {
            foreach ($booking->mealServices as $meal) {
                if (!$meal->menu_item_id) continue;
                $menuItem = $meal->menuItem;
                if (!$menuItem) continue;

                $menuItems->push([
                    'menu_item' => $menuItem,
                    'quantity' => (int) ($meal->pax ?? $pax),
                    'meal_type' => $meal->meal_type,
                    'service_date' => $meal->service_date?->toDateString(),
                ]);
            }
        }

        // Aggregate ingredient requirements
        $ingredientRequirements = [];

        foreach ($menuItems as $menuData) {
            $menuItem = $menuData['menu_item'];
            $quantity = max(1, $menuData['quantity']);

            foreach ($menuItem->recipeIngredients as $recipe) {
                $ingredientId = $recipe->ingredient_id;
                $ingredient = $recipe->ingredient;

                if (!$ingredient) continue;

                $quantityPerPax = (float) $recipe->quantity_per_pax;
                $requiredQty = $quantityPerPax * $quantity;

                if (!isset($ingredientRequirements[$ingredientId])) {
                    $ingredientRequirements[$ingredientId] = [
                        'ingredient_id' => $ingredientId,
                        'name' => $ingredient->name,
                        'unit' => $recipe->unit ?? $ingredient->unit ?? 'kg',
                        'total_quantity' => 0,
                        'unit_cost' => (float) ($ingredient->unit_cost ?? 0),
                        'menu_items' => [],
                    ];
                }

                $ingredientRequirements[$ingredientId]['total_quantity'] += $requiredQty;
                $ingredientRequirements[$ingredientId]['menu_items'][] = [
                    'menu_item_id' => $menuItem->menu_item_id,
                    'menu_name' => $menuItem->name,
                    'quantity' => $quantity,
                    'quantity_per_pax' => $quantityPerPax,
                    'required' => $requiredQty,
                ];
            }
        }

        // Calculate costs
        foreach ($ingredientRequirements as $req) {
            // Use historical cost if requested (from snapshot), otherwise current cost
            $unitCost = $req['unit_cost'];
            $lineCost = round($req['total_quantity'] * $unitCost, 2);

            $breakdown[] = [
                'ingredient_id' => $req['ingredient_id'],
                'name' => $req['name'],
                'unit' => $req['unit'],
                'quantity' => round($req['total_quantity'], 4),
                'unit_cost' => $unitCost,
                'total_cost' => $lineCost,
                'menu_items' => $req['menu_items'],
            ];

            $totalCost += $lineCost;
        }

        return [
            'total_cost' => round($totalCost, 2),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Calculate complete profitability for a booking.
     */
    public function calculateProfitability(Booking $booking, bool $saveSnapshot = false, string $snapshotType = 'projected'): array
    {
        $booking->loadMissing([
            'serviceEvent',
            'quotation',
            'invoice',
            'payments',
            'items.menuItem',
            'mealServices.menuItem',
            'charges',
            'equipment.equipment',
        ]);

        // ==================== REVENUE ====================
        $foodRevenue = 0.0;
        $serviceFee = 0.0;
        $deliveryFee = 0.0;
        $extrasRevenue = 0.0;
        $discountAmount = 0.0;

        // Calculate from meal services / items
        $mealTotal = (float) $booking->mealServices->sum('total_meal_amount');
        if ($mealTotal <= 0) {
            $mealTotal = (float) $booking->items->sum(fn($item) => (float) $item->unit_price * (int) $item->quantity);
        }

        $foodRevenue = $mealTotal;

        // Get charges from booking_charges
        foreach ($booking->charges as $charge) {
            $amount = (float) $charge->amount;
            $chargeType = strtolower($charge->charge_type ?? '');
            $description = strtolower($charge->description ?? '');

            if ($charge->charge_kind === 'discount') {
                $discountAmount += $amount;
                continue;
            }

            // Categorize charges
            if (str_contains($chargeType, 'service') || str_contains($description, 'service')) {
                $serviceFee += $amount;
            } elseif (str_contains($chargeType, 'delivery') || str_contains($description, 'delivery') || str_contains($chargeType, 'transport')) {
                $deliveryFee += $amount;
            } elseif (str_contains($chargeType, 'equipment') || str_contains($description, 'equipment')) {
                $extrasRevenue += $amount;
            } else {
                $extrasRevenue += $amount;
            }
        }

        // Fallback to invoice/quotation if no charges
        $invoiceTotal = (float) ($booking->invoice?->total_amount ?? $booking->quotation?->total_amount ?? 0);
        $calculatedTotal = $foodRevenue + $serviceFee + $deliveryFee + $extrasRevenue - $discountAmount;

        if ($calculatedTotal <= 0 && $invoiceTotal > 0) {
            $foodRevenue = $invoiceTotal;
            $calculatedTotal = $invoiceTotal;
        }

        $totalRevenue = $foodRevenue + $serviceFee + $deliveryFee + $extrasRevenue - $discountAmount;

        // ==================== COST ====================
        $ingredientData = $this->calculateIngredientCost($booking);
        $ingredientCost = $ingredientData['total_cost'];
        $ingredientBreakdown = $ingredientData['breakdown'];

        // Labor cost - from assigned staff schedules
        $laborCost = $this->calculateLaborCost($booking);

        // Delivery cost
        $deliveryCost = $this->calculateDeliveryCost($booking);

        // Equipment cost
        $equipmentCost = $this->calculateEquipmentCost($booking);

        // Other expenses
        $otherCost = $this->calculateOtherCost($booking);

        $totalCost = $ingredientCost + $laborCost + $deliveryCost + $equipmentCost + $otherCost;

        // ==================== PROFITABILITY ====================
        $profit = $totalRevenue - $totalCost;
        $profitMargin = $totalRevenue > 0 ? round(($profit / $totalRevenue) * 100, 2) : 0;
        $foodCostPercentage = $foodRevenue > 0 ? round(($ingredientCost / $foodRevenue) * 100, 2) : 0;

        // ==================== PAYMENT ====================
        $paidAmount = (float) $booking->payments->where('status', 'completed')->sum('amount');
        $balance = max(0, $totalRevenue - $paidAmount);

        $result = [
            'booking_id' => $booking->booking_id,
            'booking_no' => $booking->booking_no,

            // Revenue breakdown
            'food_revenue' => round($foodRevenue, 2),
            'service_fee' => round($serviceFee, 2),
            'delivery_fee' => round($deliveryFee, 2),
            'extras_revenue' => round($extrasRevenue, 2),
            'discount_amount' => round($discountAmount, 2),
            'total_revenue' => round($totalRevenue, 2),

            // Cost breakdown
            'ingredient_cost' => round($ingredientCost, 2),
            'labor_cost' => round($laborCost, 2),
            'delivery_cost' => round($deliveryCost, 2),
            'equipment_cost' => round($equipmentCost, 2),
            'other_cost' => round($otherCost, 2),
            'total_cost' => round($totalCost, 2),

            // Profitability
            'profit' => round($profit, 2),
            'profit_margin' => $profitMargin,
            'food_cost_percentage' => $foodCostPercentage,

            // Payment
            'paid_amount' => round($paidAmount, 2),
            'balance' => round($balance, 2),
            'payment_status' => $paidAmount <= 0 ? 'unpaid' : ($paidAmount < $totalRevenue ? 'partial' : 'paid'),

            // Breakdowns
            'ingredient_breakdown' => $ingredientBreakdown,
            'menu_breakdown' => $this->calculateMenuBreakdown($booking, $ingredientBreakdown),

            'snapshot_type' => $snapshotType,
            'calculated_at' => now()->toIso8601String(),
        ];

        // Save snapshot if requested
        if ($saveSnapshot) {
            $this->saveSnapshot($booking, $result, $snapshotType);
        }

        return $result;
    }

    /**
     * Calculate labor cost from assigned staff.
     */
    protected function calculateLaborCost(Booking $booking): float
    {
        $booking->loadMissing(['tracking']);

        $preparationTracking = $booking->tracking->where('stage', 'preparation')->first();
        if (!$preparationTracking) {
            return 0.0;
        }

        $metadata = json_decode((string) $preparationTracking->notes, true);
        if (!is_array($metadata)) {
            return 0.0;
        }

        $assignedStaff = $metadata['assigned_staff'] ?? [];
        $totalCost = 0.0;

        foreach ($assignedStaff as $staff) {
            // Try to get hourly rate from employee record
            $staffCost = (float) ($staff['hourly_rate'] ?? $staff['rate'] ?? 0);

            // If no explicit rate, use default calculation
            if ($staffCost <= 0) {
                // Default: 8 hours at estimated rate
                $staffCost = 8 * 100; // ₱100/hour default
            }

            $totalCost += $staffCost;
        }

        return $totalCost;
    }

    /**
     * Calculate delivery cost.
     */
    protected function calculateDeliveryCost(Booking $booking): float
    {
        $booking->loadMissing(['serviceEvent', 'deliveryTrackings']);

        // Check if there's an explicit delivery cost
        $deliveryMethod = strtolower($booking->serviceEvent?->delivery_method ?? '');

        // Base delivery cost by method
        $baseCost = match ($deliveryMethod) {
            'delivery' => 500,
            'pickup' => 0,
            default => 0,
        };

        // Add cost per delivery tracking
        $trackingCount = $booking->deliveryTrackings?->count() ?? 0;
        if ($trackingCount > 0) {
            $baseCost += $trackingCount * 200;
        }

        return $baseCost;
    }

    /**
     * Calculate equipment cost.
     */
    protected function calculateEquipmentCost(Booking $booking): float
    {
        $booking->loadMissing(['equipment']);

        $totalCost = 0.0;

        foreach ($booking->equipment as $item) {
            // Use rental price if available
            $rentalPrice = (float) ($item->rental_price_at_booking ?? 0);
            $quantity = (int) ($item->quantity_reserved ?? 0);

            if ($rentalPrice > 0) {
                $totalCost += $rentalPrice * $quantity;
            } else {
                // Fallback to equipment's current rental price
                $equipmentPrice = (float) ($item->equipment?->rental_price ?? 0);
                $totalCost += $equipmentPrice * $quantity;
            }
        }

        return $totalCost;
    }

    /**
     * Calculate other costs.
     */
    protected function calculateOtherCost(Booking $booking): float
    {
        // Check for explicit other costs in settings
        $setting = Setting::where('group', 'booking_costs')
            ->where('key', 'booking_' . $booking->booking_id)
            ->first();

        if ($setting) {
            $data = json_decode($setting->value, true);
            return (float) ($data['other_cost'] ?? 0);
        }

        return 0.0;
    }

    /**
     * Calculate per-menu profitability breakdown.
     */
    protected function calculateMenuBreakdown(Booking $booking, array $ingredientBreakdown): array
    {
        $booking->loadMissing(['items.menuItem', 'mealServices.menuItem', 'serviceEvent']);

        $pax = (int) ($booking->serviceEvent?->guests_count ?? 0);
        $menuData = [];

        // Collect menu items with their quantities
        $menuItems = collect();

        foreach ($booking->items as $item) {
            if (!$item->menu_item_id || !$item->menuItem) continue;
            $menuItems->push([
                'menu_item' => $item->menuItem,
                'quantity' => (int) ($item->quantity ?? $pax),
                'unit_price' => (float) ($item->unit_price ?? 0),
            ]);
        }

        if ($menuItems->isEmpty()) {
            foreach ($booking->mealServices as $meal) {
                if (!$meal->menu_item_id || !$meal->menuItem) continue;
                $menuItems->push([
                    'menu_item' => $meal->menuItem,
                    'quantity' => (int) ($meal->pax ?? $pax),
                    'unit_price' => (float) ($meal->price_per_head ?? 0),
                ]);
            }
        }

        // Calculate per-menu costs from ingredient breakdown
        foreach ($menuItems as $data) {
            $menuItem = $data['menu_item'];
            $quantity = max(1, $data['quantity']);
            $menuItemId = $menuItem->menu_item_id;

            // Sum ingredient costs attributable to this menu
            $ingredientCost = 0.0;
            foreach ($ingredientBreakdown as $ingredient) {
                foreach ($ingredient['menu_items'] as $ref) {
                    if ($ref['menu_item_id'] === $menuItemId) {
                        $ingredientCost += (float) ($ref['required'] ?? 0) * (float) ($ingredient['unit_cost'] ?? 0);
                    }
                }
            }

            $revenue = $data['unit_price'] * $quantity;
            $profit = $revenue - $ingredientCost;
            $margin = $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0;

            $menuData[] = [
                'menu_item_id' => $menuItemId,
                'menu_name' => $menuItem->name,
                'category' => $menuItem->category?->name,
                'quantity' => $quantity,
                'unit_price' => round($data['unit_price'], 2),
                'revenue' => round($revenue, 2),
                'ingredient_cost' => round($ingredientCost, 2),
                'profit' => round($profit, 2),
                'profit_margin' => $margin,
            ];
        }

        return $menuData;
    }

    /**
     * Save a cost snapshot for historical accuracy.
     */
    public function saveSnapshot(Booking $booking, array $data, string $type = 'projected'): BookingCostSnapshot
    {
        $snapshot = BookingCostSnapshot::updateOrCreate(
            ['booking_id' => $booking->booking_id],
            [
                'ingredient_cost' => $data['ingredient_cost'],
                'labor_cost' => $data['labor_cost'],
                'delivery_cost' => $data['delivery_cost'],
                'equipment_cost' => $data['equipment_cost'],
                'other_cost' => $data['other_cost'],
                'food_revenue' => $data['food_revenue'],
                'service_fee' => $data['service_fee'],
                'delivery_fee' => $data['delivery_fee'],
                'extras_revenue' => $data['extras_revenue'],
                'discount_amount' => $data['discount_amount'],
                'ingredient_breakdown' => $data['ingredient_breakdown'],
                'menu_breakdown' => $data['menu_breakdown'],
                'snapshot_type' => $type,
                'snapshotted_at' => now(),
            ]
        );

        // Update booking reference
        $booking->update(['profitability_snapshot_id' => $snapshot->id]);

        return $snapshot;
    }

    /**
     * Get profitability from snapshot (historical) or calculate fresh.
     */
    public function getProfitability(Booking $booking, bool $useSnapshot = true): array
    {
        if ($useSnapshot && $booking->profitability_snapshot_id) {
            $snapshot = BookingCostSnapshot::find($booking->profitability_snapshot_id);
            if ($snapshot) {
                return $this->formatSnapshotResponse($snapshot, $booking);
            }
        }

        return $this->calculateProfitability($booking, false);
    }

    /**
     * Format snapshot for API response.
     */
    protected function formatSnapshotResponse(BookingCostSnapshot $snapshot, Booking $booking): array
    {
        $booking->loadMissing(['payments']);

        $paidAmount = (float) $booking->payments->where('status', 'completed')->sum('amount');
        $totalRevenue = $snapshot->total_revenue;

        return [
            'booking_id' => $booking->booking_id,
            'booking_no' => $booking->booking_no,
            'food_revenue' => (float) $snapshot->food_revenue,
            'service_fee' => (float) $snapshot->service_fee,
            'delivery_fee' => (float) $snapshot->delivery_fee,
            'extras_revenue' => (float) $snapshot->extras_revenue,
            'discount_amount' => (float) $snapshot->discount_amount,
            'total_revenue' => $totalRevenue,
            'ingredient_cost' => (float) $snapshot->ingredient_cost,
            'labor_cost' => (float) $snapshot->labor_cost,
            'delivery_cost' => (float) $snapshot->delivery_cost,
            'equipment_cost' => (float) $snapshot->equipment_cost,
            'other_cost' => (float) $snapshot->other_cost,
            'total_cost' => $snapshot->total_cost,
            'profit' => $snapshot->profit,
            'profit_margin' => $snapshot->profit_margin,
            'food_cost_percentage' => $snapshot->food_cost_percentage,
            'paid_amount' => round($paidAmount, 2),
            'balance' => round(max(0, $totalRevenue - $paidAmount), 2),
            'payment_status' => $paidAmount <= 0 ? 'unpaid' : ($paidAmount < $totalRevenue ? 'partial' : 'paid'),
            'ingredient_breakdown' => $snapshot->ingredient_breakdown ?? [],
            'menu_breakdown' => $snapshot->menu_breakdown ?? [],
            'snapshot_type' => $snapshot->snapshot_type,
            'calculated_at' => $snapshot->snapshotted_at?->toIso8601String(),
        ];
    }

    /**
     * Get aggregate profitability report.
     */
    public function getAggregateReport(array $filters = []): array
    {
        $query = Booking::query()
            ->with(['serviceEvent', 'payments', 'invoice', 'quotation', 'charges', 'profitabilitySnapshot'])
            ->whereIn('booking_status', $filters['statuses'] ?? ['completed', 'confirmed']);

        // Apply date filters
        if (!empty($filters['date_from'])) {
            $query->whereHas('serviceEvent', fn($q) => $q->whereDate('event_date', '>=', $filters['date_from']));
        }
        if (!empty($filters['date_to'])) {
            $query->whereHas('serviceEvent', fn($q) => $q->whereDate('event_date', '<=', $filters['date_to']));
        }
        if (!empty($filters['event_type_id'])) {
            $query->whereHas('serviceEvent', fn($q) => $q->where('event_type_id', $filters['event_type_id']));
        }

        $bookings = $query->get();

        $totals = [
            'total_revenue' => 0,
            'total_cost' => 0,
            'total_profit' => 0,
            'total_paid' => 0,
            'total_balance' => 0,
            'ingredient_cost' => 0,
            'labor_cost' => 0,
            'delivery_cost' => 0,
            'equipment_cost' => 0,
            'other_cost' => 0,
            'food_revenue' => 0,
            'service_fee' => 0,
            'delivery_fee' => 0,
            'extras_revenue' => 0,
        ];

        $bookingRows = [];

        foreach ($bookings as $booking) {
            $profitability = $this->getProfitability($booking, true);

            $totals['total_revenue'] += $profitability['total_revenue'];
            $totals['total_cost'] += $profitability['total_cost'];
            $totals['total_profit'] += $profitability['profit'];
            $totals['total_paid'] += $profitability['paid_amount'];
            $totals['total_balance'] += $profitability['balance'];
            $totals['ingredient_cost'] += $profitability['ingredient_cost'];
            $totals['labor_cost'] += $profitability['labor_cost'];
            $totals['delivery_cost'] += $profitability['delivery_cost'];
            $totals['equipment_cost'] += $profitability['equipment_cost'];
            $totals['other_cost'] += $profitability['other_cost'];
            $totals['food_revenue'] += $profitability['food_revenue'];
            $totals['service_fee'] += $profitability['service_fee'];
            $totals['delivery_fee'] += $profitability['delivery_fee'];
            $totals['extras_revenue'] += $profitability['extras_revenue'];

            $bookingRows[] = [
                'booking_id' => $booking->booking_id,
                'booking_no' => $booking->booking_no,
                'customer_name' => $booking->serviceEvent?->customer?->person?->full_name ?? 'Unknown',
                'event_date' => $booking->serviceEvent?->event_date?->toDateString(),
                'event_type' => $booking->serviceEvent?->eventType?->name,
                'pax' => (int) ($booking->serviceEvent?->guests_count ?? 0),
                'total_revenue' => $profitability['total_revenue'],
                'total_cost' => $profitability['total_cost'],
                'profit' => $profitability['profit'],
                'profit_margin' => $profitability['profit_margin'],
                'paid_amount' => $profitability['paid_amount'],
                'balance' => $profitability['balance'],
                'payment_status' => $profitability['payment_status'],
                'booking_status' => $booking->booking_status,
            ];
        }

        $profitMargin = $totals['total_revenue'] > 0
            ? round(($totals['total_profit'] / $totals['total_revenue']) * 100, 2)
            : 0;

        $foodCostPercentage = $totals['food_revenue'] > 0
            ? round(($totals['ingredient_cost'] / $totals['food_revenue']) * 100, 2)
            : 0;

        return [
            'summary' => array_merge($totals, [
                'profit_margin' => $profitMargin,
                'food_cost_percentage' => $foodCostPercentage,
                'completed_bookings' => $bookings->where('booking_status', 'completed')->count(),
                'total_bookings' => $bookings->count(),
                'average_booking_value' => $bookings->count() > 0
                    ? round($totals['total_revenue'] / $bookings->count(), 2)
                    : 0,
            ]),
            'bookings' => $bookingRows,
        ];
    }

    /**
     * Get menu performance report.
     */
    public function getMenuPerformance(array $filters = []): array
    {
        $query = Booking::query()
            ->with([
                'serviceEvent',
                'items.menuItem.category',
                'items.menuItem.recipeIngredients.ingredient',
                'mealServices.menuItem.category',
                'mealServices.menuItem.recipeIngredients.ingredient',
            ])
            ->whereIn('booking_status', $filters['statuses'] ?? ['completed', 'confirmed']);

        if (!empty($filters['date_from'])) {
            $query->whereHas('serviceEvent', fn($q) => $q->whereDate('event_date', '>=', $filters['date_from']));
        }
        if (!empty($filters['date_to'])) {
            $query->whereHas('serviceEvent', fn($q) => $q->whereDate('event_date', '<=', $filters['date_to']));
        }

        $bookings = $query->get();

        $menuStats = [];

        foreach ($bookings as $booking) {
            $pax = (int) ($booking->serviceEvent?->guests_count ?? 0);

            // Process booking items
            $menuItems = collect();
            foreach ($booking->items as $item) {
                if (!$item->menu_item_id || !$item->menuItem) continue;
                $menuItems->push([
                    'menu_item' => $item->menuItem,
                    'quantity' => (int) ($item->quantity ?? $pax),
                    'unit_price' => (float) ($item->unit_price ?? 0),
                ]);
            }

            if ($menuItems->isEmpty()) {
                foreach ($booking->mealServices as $meal) {
                    if (!$meal->menu_item_id || !$meal->menuItem) continue;
                    $menuItems->push([
                        'menu_item' => $meal->menuItem,
                        'quantity' => (int) ($meal->pax ?? $pax),
                        'unit_price' => (float) ($meal->price_per_head ?? 0),
                    ]);
                }
            }

            foreach ($menuItems as $data) {
                $menuItem = $data['menu_item'];
                $menuItemId = $menuItem->menu_item_id;
                $quantity = max(1, $data['quantity']);

                if (!isset($menuStats[$menuItemId])) {
                    $menuStats[$menuItemId] = [
                        'menu_item_id' => $menuItemId,
                        'menu_name' => $menuItem->name,
                        'category' => $menuItem->category?->name ?? 'Uncategorized',
                        'selling_price' => (float) $menuItem->price,
                        'orders' => 0,
                        'total_pax' => 0,
                        'total_revenue' => 0,
                        'total_ingredient_cost' => 0,
                    ];
                }

                // Calculate ingredient cost for this menu
                $ingredientCost = 0.0;
                foreach ($menuItem->recipeIngredients as $recipe) {
                    $unitCost = (float) ($recipe->ingredient?->unit_cost ?? 0);
                    $requiredQty = (float) $recipe->quantity_per_pax * $quantity;
                    $ingredientCost += $requiredQty * $unitCost;
                }

                $menuStats[$menuItemId]['orders']++;
                $menuStats[$menuItemId]['total_pax'] += $quantity;
                $menuStats[$menuItemId]['total_revenue'] += $data['unit_price'] * $quantity;
                $menuStats[$menuItemId]['total_ingredient_cost'] += $ingredientCost;
            }
        }

        // Calculate derived metrics
        $result = [];
        foreach ($menuStats as $stat) {
            $revenue = $stat['total_revenue'];
            $cost = $stat['total_ingredient_cost'];
            $profit = $revenue - $cost;
            $margin = $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0;

            $result[] = [
                'menu_item_id' => $stat['menu_item_id'],
                'menu_name' => $stat['menu_name'],
                'category' => $stat['category'],
                'selling_price' => $stat['selling_price'],
                'orders' => $stat['orders'],
                'total_pax' => $stat['total_pax'],
                'total_revenue' => round($revenue, 2),
                'total_ingredient_cost' => round($cost, 2),
                'total_cost' => round($cost, 2),
                'total_profit' => round($profit, 2),
                'profit_margin' => $margin,
            ];
        }

        // Sort by revenue descending
        usort($result, fn($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);

        // Add rankings
        foreach ($result as $index => &$item) {
            $item['revenue_rank'] = $index + 1;
        }

        // Sort by profit for profit ranking
        $byProfit = $result;
        usort($byProfit, fn($a, $b) => $b['total_profit'] <=> $a['total_profit']);
        foreach ($byProfit as $index => $item) {
            foreach ($result as &$r) {
                if ($r['menu_item_id'] === $item['menu_item_id']) {
                    $r['profit_rank'] = $index + 1;
                    break;
                }
            }
        }

        return $result;
    }
}