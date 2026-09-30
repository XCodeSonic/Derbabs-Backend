<?php

namespace App\Http\Controllers\Api;

use App\Models\MenuItem;
use App\Models\RecipeIngredient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecipeController extends Controller
{
    public function index()
    {
        return $this->ok(
            MenuItem::query()
                ->with(['category', 'recipeIngredients.ingredient.stock'])
                ->latest('menu_item_id')
                ->get()
        );
    }

    public function show($menuItem)
    {
        // Try to find by ID first, then by name
        $item = MenuItem::query()
            ->where('menu_item_id', $menuItem)
            ->orWhere('name', $menuItem)
            ->with(['category', 'recipeIngredients.ingredient.stock'])
            ->first();

        // Return 200 with null data instead of 404
        if (!$item) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'Recipe not found for menu item ID: ' . $menuItem
            ], 200);
        }

        return $this->ok($item);
    }

    public function store(Request $request)
    {
        $this->normalizeIngredients($request);

        $validated = $request->validate([
            'menu_item_id' => ['required', 'integer', 'exists:menu_items,menu_item_id'],
            'ingredients' => ['required', 'array'],
            'ingredients.*.ingredient_id' => ['required', 'integer', 'distinct', 'exists:ingredients,ingredient_id'],
            'ingredients.*.quantity_per_pax' => ['required', 'numeric', 'min:0.001'],
            'ingredients.*.unit' => ['required', 'string', 'max:20'],
        ]);

        $item = DB::transaction(function () use ($validated) {
            RecipeIngredient::where('menu_item_id', $validated['menu_item_id'])->delete();

            foreach ($validated['ingredients'] as $ingredient) {
                RecipeIngredient::create([
                    'menu_item_id' => $validated['menu_item_id'],
                    'ingredient_id' => $ingredient['ingredient_id'],
                    'quantity_per_pax' => $ingredient['quantity_per_pax'],
                    'unit' => $ingredient['unit'],
                ]);
            }

            $item = MenuItem::findOrFail($validated['menu_item_id']);
            $cost = $item->recipeIngredients()
                ->with('ingredient:ingredient_id,unit_cost')
                ->get()
                ->sum(fn ($row) => (float) $row->quantity_per_pax * (float) ($row->ingredient?->unit_cost ?? 0));
            $item->update(['cost_to_make' => round($cost, 2)]);

            return $item;
        });

        return $this->ok(
            $item->load(['category', 'recipeIngredients.ingredient.stock']),
            'Recipe saved successfully'
        );
    }

    public function destroy($menuItem)
    {
        $item = MenuItem::where('menu_item_id', $menuItem)
            ->orWhere('name', $menuItem)
            ->first();

        if (!$item) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'Recipe not found for menu item ID: ' . $menuItem
            ], 200);
        }

        $item->recipeIngredients()->delete();
        $item->update(['cost_to_make' => 0]);

        return $this->ok(null, 'Recipe deleted successfully');
    }

    // Bulk fetch recipes for multiple menu items
    public function bulk(Request $request)
    {
        $ids = $request->input('ids', []);
        
        if (empty($ids)) {
            return $this->ok([], 'No IDs provided');
        }

        $items = MenuItem::whereIn('menu_item_id', $ids)
            ->with(['category', 'recipeIngredients.ingredient.stock'])
            ->get()
            ->keyBy('menu_item_id');

        $result = [];
        foreach ($ids as $id) {
            $result[$id] = $items->has($id) ? $items->get($id) : null;
        }

        return $this->ok($result);
    }

    private function findMenuItem($value): MenuItem
    {
        return MenuItem::query()
            ->where('menu_item_id', $value)
            ->orWhere('name', $value)
            ->with(['category', 'recipeIngredients.ingredient.stock'])
            ->firstOrFail();
    }

    private function normalizeIngredients(Request $request): void
    {
        if (!$request->has('ingredients') || !is_string($request->input('ingredients'))) {
            return;
        }

        $decoded = json_decode((string) $request->input('ingredients'), true);

        if (!is_array($decoded)) {
            throw ValidationException::withMessages([
                'ingredients' => 'Ingredients must be a valid JSON array.',
            ]);
        }

        $request->merge(['ingredients' => $decoded]);
    }
}