<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\PurchaseRequest;
use App\Models\Booking;
use App\Models\Setting;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    /**
     * Generate sequential purchase request number (PRQ-0013 format)
     */
    private function generatePurchaseRequestNumber(): string
    {
        return $this->generateSequentialNumber('PRQ-', PurchaseRequest::class, 'pr_number');
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
        return $prefix . now()->format('YmdHis') . '-' . random_int(100, 999);
    }
}
    public function move(int $ingredientId, float $quantityChange, string $movementType, string $reason = ''): InventoryMovement
    {
        return DB::transaction(function () use ($ingredientId, $quantityChange, $movementType, $reason) {
            $stock = InventoryStock::where('ingredient_id', $ingredientId)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                $stock = InventoryStock::create([
                    'ingredient_id' => $ingredientId,
                    'current_quantity' => 0,
                    'reserved_quantity' => 0,
                    'minimum_quantity' => 10,
                    'maximum_quantity' => 100,
                    'reorder_point' => 15,
                ]);
            }

            $quantityBefore = (float) $stock->current_quantity;
            $quantityAfter = $quantityBefore + $quantityChange;

            if ($quantityAfter < 0) {
                throw ValidationException::withMessages([
                    'quantity_change' => "Insufficient stock. Available: {$quantityBefore}; requested deduction: " . abs($quantityChange) . '.',
                ]);
            }

            $ingredient = Ingredient::findOrFail($ingredientId);
            $movement = InventoryMovement::create([
                'ingredient_id' => $ingredientId,
                'performed_by' => auth()->id() ?? 1,
                'movement_type' => $movementType,
                'quantity_change' => $quantityChange,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'unit_cost_at_time' => (float) ($ingredient->unit_cost ?? 0),
                'reason' => $reason,
            ]);

            $stockUpdate = ['current_quantity' => $quantityAfter];
            if ($quantityChange > 0) {
                $stockUpdate['last_restocked_at'] = now();
            } elseif ($quantityChange < 0) {
                $stockUpdate['last_used_at'] = now();
            }

            $stock->update($stockUpdate);
            $stock->refresh();

            $this->logInventoryMovement($movement, $stock, $movementType);
            $this->notifyStockStatus($stock);
            
            if ($quantityAfter <= $stock->reorder_point && $movementType !== 'purchase') {
                $this->checkAndCreatePurchaseRequest($ingredientId, $stock);
            }
            
            return $movement;
        });
    }

    public function reserveForBooking(Booking $booking): void
    {
        $booking->loadMissing(['items.menuItem.recipeIngredients.ingredient', 'items.mealService.eventDay']);

        foreach ($booking->items as $item) {
            if (!$item->menu_item_id) continue;
            
            $meal = $item->mealService;

            foreach ($item->menuItem->recipeIngredients as $recipe) {
                $stock = InventoryStock::where('ingredient_id', $recipe->ingredient_id)->first();
                
                if ($stock) {
                    $requiredQty = $recipe->quantity_per_pax * $item->quantity;
                    $availableStock = $stock->current_quantity - $stock->reserved_quantity;
                    
                    if ($availableStock < $requiredQty) {
                        $shortage = $requiredQty - $availableStock;
                        $this->createPurchaseRequest($recipe->ingredient_id, $shortage, 'insufficient_stock');
                        
                        Log::warning("Insufficient stock for ingredient {$recipe->ingredient->name}", [
                            'booking_id' => $booking->booking_id,
                            'required' => $requiredQty,
                            'available' => $availableStock,
                            'shortage' => $shortage,
                        ]);
                    }
                    
                    $stock->increment('reserved_quantity', $requiredQty);
                } else {
                    $requiredQty = $recipe->quantity_per_pax * $item->quantity;
                    $stock = InventoryStock::create([
                        'ingredient_id' => $recipe->ingredient_id,
                        'current_quantity' => 0,
                        'reserved_quantity' => $requiredQty,
                        'minimum_quantity' => 10,
                        'maximum_quantity' => 100,
                        'reorder_point' => 15,
                    ]);
                    
                    $this->createPurchaseRequest($recipe->ingredient_id, $requiredQty, 'no_stock');
                }
            }
        }
    }


    public function hasDeductedInventory(Booking $booking): bool
    {
        return (bool) Setting::getValue('inventory_deductions', 'booking_' . $booking->booking_id, false);
    }

    private function markInventoryDeducted(Booking $booking): void
    {
        Setting::setValue('inventory_deductions', 'booking_' . $booking->booking_id, true, 'boolean');
        Setting::setValue('inventory_deduction_timestamps', 'booking_' . $booking->booking_id, now()->toDateTimeString(), 'string');
    }

    public function deductForCompletedEvent(
        Booking $booking,
        array $confirmedDeductions = null,
        bool $allowOperationalCompletion = false
    ): array {
        return DB::transaction(function () use ($booking, $confirmedDeductions, $allowOperationalCompletion) {
            $booking->loadMissing(['serviceEvent', 'items.menuItem.recipeIngredients.ingredient', 'items.mealService.eventDay']);

            $isOperationallyCompleted = $allowOperationalCompletion
                && $booking->serviceEvent?->status === 'completed';

            if ($booking->booking_status !== 'completed' && ! $isOperationallyCompleted) {
                throw ValidationException::withMessages([
                    'booking_status' => 'Inventory can only be deducted after the event is operationally completed.',
                ]);
            }

            if ($this->hasDeductedInventory($booking)) {
                return [
                    'already_deducted' => true,
                    'message' => 'Inventory was already deducted for this completed event.',
                    'items' => [],
                ];
            }

            $requirements = [];
            foreach ($booking->items as $item) {
                if (!$item->menu_item_id || !$item->menuItem) {
                    continue;
                }

                foreach ($item->menuItem->recipeIngredients as $recipe) {
                    $ingredientId = (int) $recipe->ingredient_id;
                    $requiredQty = (float) $recipe->quantity_per_pax * (float) $item->quantity;

                    if (!isset($requirements[$ingredientId])) {
                        $requirements[$ingredientId] = [
                            'ingredient' => $recipe->ingredient,
                            'quantity' => 0,
                            'unit' => $recipe->unit,
                        ];
                    }

                    $requirements[$ingredientId]['quantity'] += $requiredQty;
                }
            }

            $results = [];
            foreach ($requirements as $ingredientId => $requirement) {
                $usedQty = (float) $requirement['quantity'];
                if ($confirmedDeductions !== null) {
                    $usedQty = (float) ($confirmedDeductions[$ingredientId] ?? 0);
                }

                if ($usedQty <= 0) {
                    continue;
                }

                $stock = InventoryStock::firstOrCreate(
                    ['ingredient_id' => $ingredientId],
                    [
                        'current_quantity' => 0,
                        'reserved_quantity' => 0,
                        'minimum_quantity' => 10,
                        'maximum_quantity' => 100,
                        'reorder_point' => 15,
                    ]
                );

                $reservedBefore = (float) $stock->reserved_quantity;
                $movement = $this->move(
                    $ingredientId,
                    -$usedQty,
                    'usage',
                    "Used for completed event booking #{$booking->booking_no}"
                );

                $stock->refresh();
                $stock->update([
                    'reserved_quantity' => max(0, $reservedBefore - $usedQty),
                    'last_used_at' => now(),
                ]);
                $stock->refresh();

                $results[$ingredientId] = [
                    'ingredient_id' => $ingredientId,
                    'ingredient' => $requirement['ingredient']?->name ?? 'Unknown',
                    'deducted' => $usedQty,
                    'unit' => $requirement['unit'],
                    'movement_id' => $movement->movement_id,
                    'remaining_reserved' => (float) $stock->reserved_quantity,
                    'remaining_stock' => (float) $stock->current_quantity,
                ];
            }

            $this->markInventoryDeducted($booking);

            return [
                'already_deducted' => false,
                'booking_id' => $booking->booking_id,
                'booking_no' => $booking->booking_no,
                'items' => array_values($results),
            ];
        });
    }

    public function getPendingDeductions(Booking $booking): array
    {
        $deductions = [];
        
        $booking->loadMissing(['items.menuItem.recipeIngredients.ingredient', 'items.mealService.eventDay']);

        foreach ($booking->items as $item) {
            if (!$item->menu_item_id) continue;
            
            $meal = $item->mealService;

            foreach ($item->menuItem->recipeIngredients as $recipe) {
                $stock = InventoryStock::where('ingredient_id', $recipe->ingredient_id)->first();
                $usedQty = $recipe->quantity_per_pax * $item->quantity;
                
                $deductions[] = [
                    'ingredient_id' => $recipe->ingredient_id,
                    'ingredient_name' => $recipe->ingredient->name,
                    'unit' => $recipe->unit,
                    'reserved_quantity' => $stock?->reserved_quantity ?? 0,
                    'to_deduct' => $usedQty,
                    'current_stock' => $stock?->current_quantity ?? 0,
                    'after_deduction' => max(0, ($stock?->current_quantity ?? 0) - $usedQty),
                    'needs_purchase' => ($stock?->current_quantity ?? 0) < $usedQty,
                ];
            }
        }
        
        return $deductions;
    }

    private function checkAndCreatePurchaseRequest(int $ingredientId, InventoryStock $stock): void
    {
        $existingRequest = PurchaseRequest::where('ingredient_id', $ingredientId)
            ->whereIn('status', ['pending', 'approved'])
            ->first();
            
        if (!$existingRequest) {
            $quantityToOrder = $stock->maximum_quantity - $stock->current_quantity;
            
            if ($quantityToOrder > 0) {
                $this->createPurchaseRequest($ingredientId, $quantityToOrder, 'auto_reorder');
            }
        }
    }

    private function createPurchaseRequest(int $ingredientId, float $quantity, string $reason): PurchaseRequest
    {
        $ingredient = Ingredient::find($ingredientId);
        
        $purchaseRequest = PurchaseRequest::create([
            'pr_number' => $this->generatePurchaseRequestNumber(),
            'ingredient_id' => $ingredientId,
            'quantity' => $quantity,
            'urgency' => $quantity <= 10 ? 'critical' : ($quantity <= 25 ? 'urgent' : 'normal'),
            'status' => 'pending',
            'notes' => "Auto-generated: {$reason} for {$ingredient?->name}",
            'requested_by' => auth()->id() ?? 1,
        ]);

        $this->logPurchaseRequest($purchaseRequest, $reason);
        $this->notifyPurchaseRequest($purchaseRequest);

        return $purchaseRequest;
    }

    private function notifyStockStatus(InventoryStock $stock): void
    {
        try {
            $stock->loadMissing('ingredient');
            if (!$stock->ingredient) {
                return;
            }

            $notificationService = app(\App\Services\NotificationService::class);
            if ((float) $stock->current_quantity <= 0) {
                $notificationService->outOfStock($stock->ingredient);
                return;
            }

            if ((float) $stock->current_quantity <= (float) $stock->reorder_point) {
                $notificationService->lowStockWarning(
                    $stock->ingredient,
                    (float) $stock->current_quantity,
                    (float) $stock->reorder_point
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Stock notification failed: ' . $e->getMessage());
        }
    }

    private function notifyPurchaseRequest(PurchaseRequest $purchaseRequest): void
    {
        try {
            app(\App\Services\NotificationService::class)->purchaseRequestGenerated($purchaseRequest->loadMissing('ingredient'));
        } catch (\Throwable $e) {
            Log::warning('Purchase request notification failed: ' . $e->getMessage());
        }
    }

    private function logPurchaseRequest(PurchaseRequest $purchaseRequest, string $reason): void
    {
        try {
            AuditLog::log('purchase_request_created', 'purchases', $purchaseRequest->purchase_request_id, null, [
                'pr_number' => $purchaseRequest->pr_number,
                'ingredient_id' => $purchaseRequest->ingredient_id,
                'quantity' => $purchaseRequest->quantity,
                'reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Purchase request audit log failed: ' . $e->getMessage());
        }
    }

    private function logInventoryMovement(InventoryMovement $movement, InventoryStock $stock, string $movementType): void
    {
        try {
            $action = match ($movementType) {
                'purchase', 'restock', 'stock_in' => 'stock_added',
                'reservation', 'reserve' => 'stock_reserved',
                'release' => 'stock_released',
                'usage', 'deduction', 'stock_out' => 'stock_deducted',
                'return' => 'stock_returned',
                'waste' => 'stock_wasted',
                default => 'stock_adjusted',
            };

            AuditLog::log($action, 'inventory', $movement->movement_id, null, [
                'ingredient_id' => $movement->ingredient_id,
                'movement_type' => $movementType,
                'quantity_change' => $movement->quantity_change,
                'quantity_before' => $movement->quantity_before,
                'quantity_after' => $movement->quantity_after,
                'current_quantity' => $stock->current_quantity,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Inventory audit log failed: ' . $e->getMessage());
        }
    }

    public function getInventoryAlerts(): array
    {
        $alerts = [];
        
        $stocks = InventoryStock::with('ingredient')->get();
        
        foreach ($stocks as $stock) {
            $availableStock = $stock->current_quantity - $stock->reserved_quantity;
            
            if ($availableStock <= 0) {
                $alerts[] = [
                    'type' => 'critical',
                    'ingredient' => $stock->ingredient->name,
                    'message' => "OUT OF STOCK - No {$stock->ingredient->name} available",
                    'current_stock' => $stock->current_quantity,
                    'reserved' => $stock->reserved_quantity,
                    'available' => $availableStock,
                    'needs_purchase' => true,
                ];
            } elseif ($availableStock <= $stock->reorder_point) {
                $alerts[] = [
                    'type' => 'warning',
                    'ingredient' => $stock->ingredient->name,
                    'message' => "LOW STOCK - {$stock->ingredient->name} is below reorder point",
                    'current_stock' => $stock->current_quantity,
                    'reserved' => $stock->reserved_quantity,
                    'available' => $availableStock,
                    'reorder_point' => $stock->reorder_point,
                    'needs_purchase' => true,
                ];
            }
            
            if ($stock->expiry_date && $stock->expiry_date->isPast()) {
                $alerts[] = [
                    'type' => 'expired',
                    'ingredient' => $stock->ingredient->name,
                    'message' => "EXPIRED - {$stock->ingredient->name} expired on {$stock->expiry_date->toDateString()}",
                    'expiry_date' => $stock->expiry_date->toDateString(),
                    'quantity' => $stock->current_quantity,
                ];
            } elseif ($stock->expiry_date && $stock->expiry_date->diffInDays(now()) <= 7) {
                $alerts[] = [
                    'type' => 'expiring',
                    'ingredient' => $stock->ingredient->name,
                    'message' => "EXPIRING SOON - {$stock->ingredient->name} expires in {$stock->expiry_date->diffInDays(now())} days",
                    'expiry_date' => $stock->expiry_date->toDateString(),
                    'days_left' => $stock->expiry_date->diffInDays(now()),
                ];
            }
        }
        
        return $alerts;
    }

    public function getIngredientRequirements(Booking $booking): array
    {
        $requirements = [];
        
        $booking->loadMissing(['items.menuItem.recipeIngredients.ingredient', 'items.mealService.eventDay']);

        foreach ($booking->items as $item) {
            if (!$item->menu_item_id) continue;
            
            $meal = $item->mealService;

            foreach ($item->menuItem->recipeIngredients as $recipe) {
                $requiredQty = $recipe->quantity_per_pax * $item->quantity;
                $stock = InventoryStock::where('ingredient_id', $recipe->ingredient_id)->first();
                $availableStock = $stock ? $stock->current_quantity - $stock->reserved_quantity : 0;
                
                if (!isset($requirements[$recipe->ingredient_id])) {
                    $requirements[$recipe->ingredient_id] = [
                        'ingredient_id' => $recipe->ingredient_id,
                        'name' => $recipe->ingredient->name,
                        'unit' => $recipe->unit,
                        'required_total' => 0,
                        'current_stock' => $stock?->current_quantity ?? 0,
                        'reserved' => $stock?->reserved_quantity ?? 0,
                        'available' => $availableStock,
                        'per_pax' => $recipe->quantity_per_pax,
                        'menu_items' => [],
                    ];
                }
                
                $requirements[$recipe->ingredient_id]['required_total'] += $requiredQty;
                $requirements[$recipe->ingredient_id]['menu_items'][] = [
                    'menu_item' => $item->menuItem->name,
                    'quantity' => $item->quantity,
                    'per_pax' => $recipe->quantity_per_pax,
                    'required' => $requiredQty,
                    'meal_service_id' => $item->meal_service_id,
                    'meal_type' => $meal?->meal_type,
                    'day_number' => $meal?->eventDay?->day_number,
                    'service_date' => optional($meal?->service_date)->toDateString(),
                    'serving_time' => $meal?->serving_time,
                ];
            }
        }
        
        foreach ($requirements as &$req) {
            $req['shortage'] = max(0, $req['required_total'] - $req['available']);
            $req['need_to_buy'] = $req['shortage'] > 0;
            $req['status'] = $req['shortage'] > 0 ? 'insufficient' : ($req['available'] < $req['required_total'] * 1.2 ? 'low' : 'sufficient');
        }
        
        return array_values($requirements);
    }

    public function getEquipmentAvailabilityByDate(int $equipmentId, string $date): array
    {
        $equipment = \App\Models\Equipment::find($equipmentId);
        if (!$equipment) {
            return ['available' => 0, 'total' => 0, 'is_available' => false];
        }

        $reserved = \App\Models\BookingEquipment::where('equipment_id', $equipmentId)
            ->whereIn('status', ['reserved', 'checked_out'])
            ->whereDate('rental_start_date', '<=', $date)
            ->whereDate('rental_end_date', '>=', $date)
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
}