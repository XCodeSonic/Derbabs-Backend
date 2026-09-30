<?php

namespace App\Http\Controllers\Api;

use App\Models\Ingredient;
use App\Models\InventoryStock;
use App\Models\AuditLog;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class IngredientController extends Controller
{
    public function index(Request $request)
    {
        $query = Ingredient::query()->with('stock');

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(fn ($builder) => $builder
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('category', 'like', "%{$search}%"));
        }

        if ($request->has('active') || $request->has('is_active')) {
            $query->where('is_active', $request->boolean($request->has('active') ? 'active' : 'is_active'));
        }

        if ($request->boolean('low_stock')) {
            $query->whereHas('stock', fn ($builder) => $builder->whereColumn('current_quantity', '<=', 'reorder_point'));
        }

        $rows = $query->latest('ingredient_id')->paginate($this->perPage($request));
        $rows->getCollection()->transform(fn (Ingredient $ingredient) => $this->formatIngredient($ingredient));

        return $this->ok($rows);
    }

    public function lowStock(Request $request)
    {
        $request->merge(['low_stock' => true]);
        return $this->index($request);
    }
    public function store(Request $request, InventoryService $inventory)
    {
        $validated = $this->validatePayload($request);

        $ingredient = DB::transaction(function () use ($validated) {
            $ingredient = Ingredient::create($this->ingredientFields($validated));
            $ingredient->stock()->create($this->stockFields($validated, true));
            return $ingredient->fresh('stock');
        });

        $initialQty = (float) ($validated['current_quantity']
            ?? $validated['current_stock']
            ?? $validated['quantity']
            ?? 0);

        if ($initialQty > 0) {
            $inventory->move(
                (int) $ingredient->ingredient_id,
                $initialQty,
                'purchase',
                'Ingredients Management: initial stock for ' . $ingredient->name
            );
        }

        $this->logIngredientAction('stock_added', $ingredient, ['source' => 'ingredient_created']);
        $this->notifyStockStatus($ingredient);

        return $this->ok($this->formatIngredient($ingredient), 'Ingredient created successfully.');
    }
    public function show(Ingredient $ingredient)
    {
        $ingredient->load(['stock', 'movements']);
        return $this->ok($this->formatIngredient($ingredient, true));
    }

    public function update(Request $request, Ingredient $ingredient)
    {
        $validated = $this->validatePayload($request, $ingredient);

        DB::transaction(function () use ($ingredient, $validated) {
            $ingredientFields = $this->ingredientFields($validated, false);
            if ($ingredientFields !== []) {
                $ingredient->update($ingredientFields);
            }

            $stockFields = $this->stockFields($validated, false);
            if ($stockFields !== []) {
                $ingredient->stock()->updateOrCreate([], $stockFields);
            }
        });

        $fresh = $ingredient->fresh('stock');
        $this->logIngredientAction('stock_adjusted', $fresh, ['source' => 'ingredient_updated']);
        $this->notifyStockStatus($fresh);

        return $this->show($fresh);
    }

      public function updateStock(Request $request, Ingredient $ingredient, InventoryService $inventory)
    {
        $validated = $request->validate([
            'current_stock' => ['nullable', 'numeric', 'min:0'],
            'current_quantity' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
        ]);
        $newQty = $validated['current_stock'] ?? $validated['current_quantity'] ?? $validated['quantity'] ?? null;
        abort_if($newQty === null, 422, 'A stock quantity is required.');

        $ingredient->loadMissing('stock');
        $oldQty = (float) ($ingredient->stock?->current_quantity ?? 0);
        $delta = (float) $newQty - $oldQty;

        if (abs($delta) > 0.0001) {
            $inventory->move(
                (int) $ingredient->ingredient_id,
                $delta,
                $delta > 0 ? 'purchase' : 'usage',
                "Ingredients Management: manual stock update (was {$oldQty}, now {$newQty})"
            );
        } else {
            $ingredient->stock()->updateOrCreate([], ['current_quantity' => $newQty]);
        }

        $fresh = $ingredient->fresh('stock');
        $this->logIngredientAction('stock_adjusted', $fresh, ['source' => 'stock_update', 'current_quantity' => $newQty]);
        $this->notifyStockStatus($fresh);
        return $this->show($fresh);
    }

    public function destroy(Ingredient $ingredient)
    {
        $ingredient->delete();
        return $this->ok(null, 'Ingredient archived successfully.');
    }

    public function restore(int $id)
    {
        $ingredient = Ingredient::onlyTrashed()->findOrFail($id);
        $ingredient->restore();
        return $this->ok($this->formatIngredient($ingredient->fresh('stock')), 'Ingredient restored successfully.');
    }

    public function stats()
    {
        return $this->ok([
            'total' => Ingredient::count(),
            'low_stock' => InventoryStock::whereColumn('current_quantity', '<=', 'reorder_point')->count(),
            'out_of_stock' => InventoryStock::where('current_quantity', '<=', 0)->count(),
            'reserved_quantity' => (float) InventoryStock::sum('reserved_quantity'),
        ]);
    }

    private function notifyStockStatus(Ingredient $ingredient): void
    {
        try {
            $ingredient->loadMissing('stock');
            $stock = $ingredient->stock;
            if (!$stock) {
                return;
            }

            $notificationService = app(\App\Services\NotificationService::class);
            if ((float) $stock->current_quantity <= 0) {
                $notificationService->outOfStock($ingredient);
                return;
            }

            if ((float) $stock->current_quantity <= (float) $stock->reorder_point) {
                $notificationService->lowStockWarning($ingredient, (float) $stock->current_quantity, (float) $stock->reorder_point);
            }
        } catch (\Throwable $e) {
            Log::warning('Ingredient stock notification failed: ' . $e->getMessage());
        }
    }

    private function logIngredientAction(string $action, Ingredient $ingredient, array $extra = []): void
    {
        try {
            $ingredient->loadMissing('stock');
            AuditLog::log($action, 'inventory', $ingredient->ingredient_id, null, array_merge([
                'ingredient_id' => $ingredient->ingredient_id,
                'name' => $ingredient->name,
                'current_quantity' => $ingredient->stock?->current_quantity,
                'reorder_point' => $ingredient->stock?->reorder_point,
            ], $extra));
        } catch (\Throwable $e) {
            Log::warning('Ingredient audit log failed: ' . $e->getMessage());
        }
    }

    private function validatePayload(Request $request, ?Ingredient $ingredient = null): array
    {
        $request->merge(array_filter([
            'name' => $request->input('name', $request->input('product_name')),
            'ingredient_type' => $this->normalizeIngredientType($request->input('ingredient_type', $request->input('type'))),
            'unit_cost' => $request->input('unit_cost', $request->input('cost_price')),
            'current_quantity' => $request->input('current_quantity', $request->input('stock', $request->input('current_stock'))),
        ], static fn ($value) => $value !== null));

        $required = $ingredient ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:100', Rule::unique('ingredients', 'name')->ignore($ingredient?->ingredient_id, 'ingredient_id')],
            'product_name' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:50'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'numeric', 'min:0'],
            'sku' => ['nullable', 'string', 'max:50', Rule::unique('ingredients', 'sku')->ignore($ingredient?->ingredient_id, 'ingredient_id')],
            'unit' => [$required, 'string', 'max:20'],
            'category' => ['nullable', 'string', 'max:50'],
            'ingredient_type' => ['nullable', Rule::in(['direct', 'reusable', 'estimated'])],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'lead_time' => ['nullable', 'integer', 'min:0'],
            'yield_percentage' => ['nullable', 'integer', 'between:1,100'],
            'reuse_factor' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'current_quantity' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'current_stock' => ['nullable', 'numeric', 'min:0'],
            'reserved_quantity' => ['nullable', 'numeric', 'min:0'],
            'reorder_point' => ['nullable', 'numeric', 'min:0'],
            'minimum_quantity' => ['nullable', 'numeric', 'min:0'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'maximum_quantity' => ['nullable', 'numeric', 'min:0'],
            'max_stock' => ['nullable', 'numeric', 'min:0'],
            'max_stock_level' => ['nullable', 'numeric', 'min:0'],
            'storage_location' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'expiry_date' => ['nullable', 'date'],
        ]);
    }

    private function ingredientFields(array $data, bool $withDefaults = true): array
    {
        $fields = collect($data)->only(['name', 'sku', 'unit', 'category', 'ingredient_type', 'yield_percentage', 'reuse_factor', 'notes'])->all();
        if (array_key_exists('unit_cost', $data) || array_key_exists('cost_per_unit', $data)) {
            $fields['unit_cost'] = $data['unit_cost'] ?? $data['cost_per_unit'];
        }
        if (array_key_exists('lead_time_days', $data) || array_key_exists('lead_time', $data)) {
            $fields['lead_time_days'] = $data['lead_time_days'] ?? $data['lead_time'];
        }
        if (array_key_exists('is_active', $data) || array_key_exists('active', $data)) {
            $fields['is_active'] = $data['is_active'] ?? $data['active'];
        } elseif ($withDefaults) {
            $fields['is_active'] = true;
        }
        return $fields;
    }

    private function stockFields(array $data, bool $withDefaults): array
    {
        $map = [
            'current_quantity' => ['current_quantity', 'current_stock', 'quantity'],
            'reserved_quantity' => ['reserved_quantity'],
            'minimum_quantity' => ['minimum_quantity', 'min_stock'],
            'maximum_quantity' => ['maximum_quantity', 'max_stock'],
            'max_stock_level' => ['max_stock_level'],
            'reorder_point' => ['reorder_point'],
            'storage_location' => ['storage_location', 'location'],
            'expiry_date' => ['expiry_date'],
        ];
        $fields = [];
        foreach ($map as $target => $aliases) {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $data)) {
                    $fields[$target] = $data[$alias];
                    break;
                }
            }
        }
        if ($withDefaults) {
            $fields += [
                'current_quantity' => 0,
                'reserved_quantity' => 0,
                'minimum_quantity' => 10,
                'maximum_quantity' => 100,
                'reorder_point' => 15,
            ];
            $fields['max_stock_level'] = $fields['max_stock_level'] ?? $fields['maximum_quantity'];
        }
        return $fields;
    }

    private function formatIngredient(Ingredient $ingredient, bool $withMovements = false): array
    {
        $ingredient->loadMissing('stock');
        $stock = $ingredient->stock;
        $quantity = (float) ($stock?->current_quantity ?? 0);
        $minimum = (float) ($stock?->minimum_quantity ?? 0);
        $maximum = (float) ($stock?->maximum_quantity ?? 0);
        $expiry = $stock?->expiry_date?->toDateString();

        $stockStatus = match (true) {
            $expiry && now()->startOfDay()->greaterThan($stock->expiry_date->startOfDay()) => 'expired',
            $quantity <= 0 => 'out_of_stock',
            $quantity <= $minimum => 'low_stock',
            $maximum > 0 && $quantity >= $maximum => 'over_stock',
            default => 'in_stock',
        };

        $typeLabel = match ($ingredient->ingredient_type) {
            'reusable' => 'Reusable',
            'estimated' => 'Estimated',
            default => 'Direct Ingredients',
        };

        $formatted = [
            'id' => $ingredient->ingredient_id,
            'ingredient_id' => $ingredient->ingredient_id,
            'product_id' => sprintf('PRD-%03d', $ingredient->ingredient_id),
            'display_id' => sprintf('PRD-%03d', $ingredient->ingredient_id),
            'name' => $ingredient->name,
            'product_name' => $ingredient->name,
            'sku' => $ingredient->sku,
            'unit' => $ingredient->unit,
            'category' => $ingredient->category,
            'ingredient_type' => $ingredient->ingredient_type,
            'type' => $typeLabel,
            'unit_cost' => (float) ($ingredient->unit_cost ?? 0),
            'cost_price' => (float) ($ingredient->unit_cost ?? 0),
            'cost_per_unit' => (float) ($ingredient->unit_cost ?? 0),
            'lead_time_days' => (int) $ingredient->lead_time_days,
            'lead_time' => (int) $ingredient->lead_time_days,
            'yield_percentage' => (int) $ingredient->yield_percentage,
            'reuse_factor' => (float) $ingredient->reuse_factor,
            'notes' => $ingredient->notes,
            'is_active' => (bool) $ingredient->is_active,
            'active' => (bool) $ingredient->is_active,
            'product_status' => $ingredient->is_active ? 'Active' : 'Not Active',
            'quantity' => $quantity,
            'stock' => $quantity,
            'current_stock' => $quantity,
            'current_quantity' => $quantity,
            'reserved' => (float) ($stock?->reserved_quantity ?? 0),
            'reserved_quantity' => (float) ($stock?->reserved_quantity ?? 0),
            'available_quantity' => (float) ($stock?->available_quantity ?? max(0, $quantity - (float) ($stock?->reserved_quantity ?? 0))),
            'min_stock' => $minimum,
            'minimum_quantity' => $minimum,
            'max_stock' => $maximum,
            'maximum_quantity' => $maximum,
            'reorder_point' => (float) ($stock?->reorder_point ?? 0),
            'location' => $stock?->storage_location,
            'storage_location' => $stock?->storage_location,
            'expiry_date' => $expiry,
            'status' => $stockStatus,
            'stock_status' => $stockStatus,
            'created_at' => $ingredient->created_at,
            'updated_at' => $ingredient->updated_at,
        ];

        if ($withMovements) {
            $formatted['movements'] = $ingredient->movements ?? collect();
        }

        return $formatted;
    }

    private function normalizeIngredientType(mixed $type): mixed
    {
        if ($type === null || $type === '') {
            return $type;
        }

        return match (strtolower(trim((string) $type))) {
            'direct ingredients', 'direct ingredient', 'direct' => 'direct',
            'reusable' => 'reusable',
            'estimated' => 'estimated',
            default => strtolower(trim((string) $type)),
        };
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 20), 1), 500);
    }
}
