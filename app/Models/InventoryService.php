<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\PurchaseRequest;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryService
{
    public function move(int $ingredientId, float $quantityChange, string $movementType, string $reason = ''): InventoryMovement
    {
        return DB::transaction(function () use ($ingredientId, $quantityChange, $movementType, $reason) {
            $stock = InventoryStock::where('ingredient_id', $ingredientId)->first();
            
            if (!$stock) {
                $stock = InventoryStock::create([
                    'ingredient_id' => $ingredientId,
                    'current_quantity' => 0,
                    'reserved_quantity' => 0,
                    'minimum_quantity' => 10,
                    'maximum_quantity' => 100,
                    'reorder_point' => 15,
                ]);
            }
            
            $quantityBefore = $stock->current_quantity;
            $quantityAfter = max(0, $quantityBefore + $quantityChange);
            
            $movement = InventoryMovement::create([
                'ingredient_id' => $ingredientId,
                'performed_by' => auth()->id(),
                'movement_type' => $movementType,
                'quantity_change' => $quantityChange,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'reason' => $reason,
            ]);
            
            $stock->update(['current_quantity' => $quantityAfter]);
            
            if ($quantityAfter <= $stock->reorder_point && $movementType !== 'purchase') {
                $this->checkAndCreatePurchaseRequest($ingredientId, $stock);
            }
            
            return $movement;
        });
    }

    public function reserveForBooking(Booking $booking): void
    {
        foreach ($booking->items as $item) {
            if (!$item->menu_item_id) continue;
            
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

    public function deductForCompletedEvent(Booking $booking, array $confirmedDeductions = null): array
    {
        return DB::transaction(function () use ($booking, $confirmedDeductions) {
            $results = [];
            
            foreach ($booking->items as $item) {
                if (!$item->menu_item_id) continue;
                
                foreach ($item->menuItem->recipeIngredients as $recipe) {
                    $stock = InventoryStock::where('ingredient_id', $recipe->ingredient_id)->first();
                    
                    if ($stock) {
                        $usedQty = $recipe->quantity_per_pax * $item->quantity;
                        
                        $shouldDeduct = true;
                        if ($confirmedDeductions && isset($confirmedDeductions[$recipe->ingredient_id])) {
                            $usedQty = $confirmedDeductions[$recipe->ingredient_id];
                            $shouldDeduct = $usedQty > 0;
                        }
                        
                        if ($shouldDeduct) {
                            $stock->decrement('reserved_quantity', $usedQty);
                            $stock->decrement('current_quantity', $usedQty);
                            
                            $this->move(
                                $recipe->ingredient_id,
                                -$usedQty,
                                'usage',
                                "Used for completed event booking #{$booking->booking_no}"
                            );
                            
                            $results[$recipe->ingredient_id] = [
                                'ingredient' => $recipe->ingredient->name,
                                'deducted' => $usedQty,
                                'remaining_reserved' => $stock->reserved_quantity,
                                'remaining_stock' => $stock->current_quantity,
                            ];
                        }
                    }
                }
            }
            
            \App\Models\Setting::setValue('inventory_deductions', 'booking_' . $booking->booking_id, true, 'boolean');
            
            return $results;
        });
    }

    public function getPendingDeductions(Booking $booking): array
    {
        $deductions = [];
        
        foreach ($booking->items as $item) {
            if (!$item->menu_item_id) continue;
            
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
        
        return PurchaseRequest::create([
            'pr_number' => 'PRQ-' . now()->format('YmdHis') . '-' . random_int(100, 999),
            'ingredient_id' => $ingredientId,
            'quantity' => $quantity,
            'urgency' => $quantity <= 10 ? 'critical' : ($quantity <= 25 ? 'urgent' : 'normal'),
            'status' => 'pending',
            'notes' => "Auto-generated: {$reason} for {$ingredient?->name}",
            'requested_by' => auth()->id() ?? 1,
        ]);
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
        
        foreach ($booking->items as $item) {
            if (!$item->menu_item_id) continue;
            
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
}