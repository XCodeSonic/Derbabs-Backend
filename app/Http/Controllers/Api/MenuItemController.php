<?php

namespace App\Http\Controllers\Api;

use App\Models\MenuItem;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MenuItemController extends Controller
{
    public function index(Request $request)
    {
        $query = MenuItem::query()
            ->with([
                'category',
                'recipeIngredients.ingredient.stock',
            ]);

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->has('is_available') && $request->input('is_available') !== '') {
            $query->where('is_available', $request->boolean('is_available'));
        }

        if ($request->has('is_popular') && $request->input('is_popular') !== '') {
            $query->where('is_popular', $request->boolean('is_popular'));
        }

        // For public routes, only show available items
        if ($request->is('api/v1/public/*')) {
            $query->where('is_available', true);
        }

        $menuItems = $query->latest('menu_item_id')->paginate($this->perPage($request));

        $menuItems->getCollection()->transform(function ($item) {
            return $this->formatMenuItem($item);
        });

        return response()->json([
            'success' => true,
            'data' => $menuItems,
            'message' => 'Menu items retrieved successfully'
        ]);
    }

    public function store(Request $request)
    {
        $this->normalizeIngredients($request);
        $validated = $this->validatePayload($request);

        $menuItem = DB::transaction(function () use ($request, $validated) {
            $data = Arr::except($validated, ['image', 'ingredients']);
            $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['name']);
            $data['is_available'] = $data['is_available'] ?? true;

            // Set default pricing type if not provided
            $data['pricing_type'] = $data['pricing_type'] ?? 'both';
            $data['tray_price'] = $data['tray_price'] ?? 0;
            $data['tray_servings'] = $data['tray_servings'] ?? 25;
            $data['tray_min_pax'] = $data['tray_min_pax'] ?? 20;
            $data['tray_max_pax'] = $data['tray_max_pax'] ?? 25;
            $data['tray_description'] = $data['tray_description'] ?? null;

            if ($request->hasFile('image')) {
                $data['image_url'] = $this->storeImage($request);
            }

            $menuItem = MenuItem::create($data);
            $this->syncIngredients($menuItem, $validated['ingredients'] ?? []);
            $this->recalculateCost($menuItem);

            return $menuItem;
        });

        return response()->json([
            'success' => true,
            'data' => $this->formatMenuItem($menuItem->load(['category', 'recipeIngredients.ingredient.stock'])),
            'message' => 'Menu item created successfully'
        ]);
    }

    public function show(MenuItem $menuItem)
    {
        return response()->json([
            'success' => true,
            'data' => $this->formatMenuItem($menuItem->load([
                'category',
                'recipeIngredients.ingredient.stock',
            ])),
            'message' => 'Menu item retrieved successfully'
        ]);
    }

    public function update(Request $request, MenuItem $menuItem)
    {
        $this->normalizeIngredients($request);
        $validated = $this->validatePayload($request, $menuItem);

        DB::transaction(function () use ($request, $validated, $menuItem) {
            $data = Arr::except($validated, ['image', 'ingredients']);

            if ($request->has('name') || $request->has('slug')) {
                $data['slug'] = $this->uniqueSlug(
                    $data['slug'] ?? $data['name'] ?? $menuItem->name,
                    $menuItem->menu_item_id
                );
            }

            // Handle pricing fields
            if ($request->has('pricing_type')) {
                $data['pricing_type'] = $validated['pricing_type'];
            }
            if ($request->has('tray_price')) {
                $data['tray_price'] = $validated['tray_price'];
            }
            if ($request->has('tray_servings')) {
                $data['tray_servings'] = $validated['tray_servings'];
            }
            if ($request->has('tray_min_pax')) {
                $data['tray_min_pax'] = $validated['tray_min_pax'];
            }
            if ($request->has('tray_max_pax')) {
                $data['tray_max_pax'] = $validated['tray_max_pax'];
            }
            if ($request->has('tray_description')) {
                $data['tray_description'] = $validated['tray_description'];
            }

            if ($request->hasFile('image')) {
                $this->deleteStoredImage($menuItem->image_url);
                $data['image_url'] = $this->storeImage($request);
            }

            $menuItem->update($data);

            if ($request->has('ingredients')) {
                $this->syncIngredients($menuItem, $validated['ingredients'] ?? []);
                $this->recalculateCost($menuItem);
            }
        });

        return response()->json([
            'success' => true,
            'data' => $this->formatMenuItem($menuItem->fresh()->load(['category', 'recipeIngredients.ingredient.stock'])),
            'message' => 'Menu item updated successfully'
        ]);
    }

    public function destroy(MenuItem $menuItem)
    {
        $this->deleteStoredImage($menuItem->image_url);
        $menuItem->delete();

        return response()->json([
            'success' => true,
            'message' => 'Menu item deleted successfully'
        ]);
    }

    public function toggleAvailability(MenuItem $menuItem)
    {
        $menuItem->update(['is_available' => !$menuItem->is_available]);

        return response()->json([
            'success' => true,
            'data' => $this->formatMenuItem($menuItem->fresh()),
            'message' => 'Availability updated successfully'
        ]);
    }

    public function toggleFeatured(MenuItem $menuItem)
    {
        $menuItem->update(['is_popular' => !$menuItem->is_popular]);

        return response()->json([
            'success' => true,
            'data' => $this->formatMenuItem($menuItem->fresh()),
            'message' => 'Featured status updated successfully'
        ]);
    }

    // ==================== HELPER METHODS ====================

    private function formatMenuItem($item): array
    {
        $imageUrl = $this->getFullImageUrl($item->image_url);
        $ratingStats = $this->ratingStatsForMenuItem($item->menu_item_id);

        return [
            'menu_item_id' => $item->menu_item_id,
            'id' => $item->menu_item_id,
            'category_id' => $item->category_id,
            'name' => $item->name,
            'slug' => $item->slug,
            'description' => $item->description,
            'price' => (float) $item->price,
            'cost_to_make' => (float) $item->cost_to_make,
            'prep_time_minutes' => (int) $item->prep_time_minutes,
            'serving_size' => (int) $item->serving_size,
            'is_available' => (bool) $item->is_available,
            'is_popular' => (bool) $item->is_popular,
            'is_vegetarian' => (bool) $item->is_vegetarian,
            'is_vegan' => (bool) $item->is_vegan,
            'is_gluten_free' => (bool) $item->is_gluten_free,
            'is_halal' => (bool) $item->is_halal,

            // ==================== NEW PRICING FIELDS ====================
            'pricing_type' => $item->pricing_type ?? 'both',
            'tray_price' => (float) ($item->tray_price ?? 0),
            'tray_servings' => (int) ($item->tray_servings ?? 25),
            'tray_min_pax' => (int) ($item->tray_min_pax ?? 20),
            'tray_max_pax' => (int) ($item->tray_max_pax ?? 25),
            'tray_description' => $item->tray_description ?? null,
            'tray_display_description' => $item->tray_display_description,
            'has_tray_pricing' => $item->hasTrayPricing(),
            'has_per_pax_pricing' => $item->hasPerPaxPricing(),

            'rating' => $ratingStats['average'],
            'average_rating' => $ratingStats['average'],
            'rating_count' => $ratingStats['count'],
                            // ⭐ Normalized allergens array + raw string + mobile-friendly aliases.
            'allergens'         => $item->allergens,
            'allergens_array'   => $this->normalizeAllergensForResponse($item->allergens),
            // Mobile detail modal reads these exact keys.
            'allergy_info'         => $this->normalizeAllergensForResponse($item->allergens),
            'allergy_information'  => $this->normalizeAllergensForResponse($item->allergens),

            // ⭐ Dietary flags bundled into an array for the mobile modal.
            //    The boolean flags are also kept for other consumers.
            'dietary_info'         => $this->buildDietaryList($item),
            'dietary_information'  => $this->buildDietaryList($item),
            'nutritional_info' => $item->nutritional_info,
            'ingredients_list' => $item->ingredients_list,
            'image_url' => $imageUrl,
            'image' => $imageUrl,
            'category' => $item->category ? [
                'id' => $item->category->category_id,
                'name' => $item->category->name,
            ] : null,
            'recipe_ingredients' => $item->recipeIngredients,
            'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
        ];
    }

    /**
     * ⭐ Build a human-readable dietary list from the boolean flags.
     *    Used by the mobile app's menu detail modal.
     */
    private function buildDietaryList($item): array
    {
        $list = [];
        if (!empty($item->is_vegetarian))  $list[] = 'Vegetarian';
        if (!empty($item->is_vegan))       $list[] = 'Vegan';
        if (!empty($item->is_gluten_free)) $list[] = 'Gluten-Free';
        if (!empty($item->is_halal))       $list[] = 'Halal';
        return $list;
    }

    /**
     * ⭐ Convert the stored allergens string ("Peanuts, Soy") into an
     *    array of lowercased slugs ("peanuts","soy") so the mobile app
     *    can match against the master `food_allergens` list.
     */
    private function normalizeAllergensForResponse($raw): array
    {
        if (empty($raw)) return [];

        // Already an array (JSON cast on the model).
        if (is_array($raw)) {
            return array_values(array_filter(array_map(
                fn ($v) => is_string($v) ? strtolower(trim($v)) : null,
                $raw
            )));
        }

        // String — split by comma.
        if (is_string($raw)) {
            return array_values(array_filter(array_map(
                fn ($v) => strtolower(trim($v)),
                explode(',', $raw)
            )));
        }

        return [];
    }

    private function getFullImageUrl(?string $imagePath): string
    {
        if (empty($imagePath)) {
            return $this->placeholderImageUrl('No Image');
        }

        if (str_starts_with($imagePath, 'data:image/')) {
            return $imagePath;
        }

        if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
            return $imagePath;
        }

        $cleanPath = ltrim($imagePath, '/');

        if (str_starts_with($cleanPath, 'storage/')) {
            $cleanPath = substr($cleanPath, 8);
        }

        if (Storage::disk('public')->exists($cleanPath)) {
            return Storage::disk('public')->url($cleanPath);
        }

        if (Storage::disk('public')->exists('storage/' . $cleanPath)) {
            return Storage::disk('public')->url('storage/' . $cleanPath);
        }

        if (Storage::disk('public')->exists($imagePath)) {
            return Storage::disk('public')->url($imagePath);
        }

        try {
            $url = Storage::disk('public')->url($cleanPath);
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                return $url;
            }
        } catch (\Exception $e) {
            // Fall through to placeholder
        }

        return $this->placeholderImageUrl('No Image');
    }

    private function storeImage(Request $request): string
    {
        $file = $request->file('image');
        $path = $file->store('menu-items', 'public');

        \Log::info('Image stored at: ' . $path);
        \Log::info('Full URL: ' . Storage::disk('public')->url($path));

        return $path;
    }

    private function deleteStoredImage(?string $imagePath): void
    {
        if (empty($imagePath)) {
            return;
        }

        if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
            $parsed = parse_url($imagePath);
            $path = ltrim($parsed['path'] ?? '', '/');
            $path = str_replace('storage/', '', $path);
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
            return;
        }

        if (Storage::disk('public')->exists($imagePath)) {
            Storage::disk('public')->delete($imagePath);
        }
    }

    private function validatePayload(Request $request, ?MenuItem $menuItem = null): array
    {
        $isUpdate = $menuItem !== null;
        $required = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'category_id' => [$required, 'integer', 'exists:meal_categories,category_id'],
            'name' => [$required, 'string', 'max:100'],
            'slug' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('menu_items', 'slug')
                    ->ignore($menuItem?->menu_item_id, 'menu_item_id'),
            ],
            'description' => ['nullable', 'string'],
            'price' => [$required, 'numeric', 'min:0'],
            'cost_to_make' => ['nullable', 'numeric', 'min:0'],
            'prep_time_minutes' => ['nullable', 'integer', 'min:0'],
            'serving_size' => ['nullable', 'integer', 'min:1'],
            'is_available' => ['nullable', 'boolean'],
            'is_popular' => ['nullable', 'boolean'],
            'is_vegetarian' => ['nullable', 'boolean'],
            'is_vegan' => ['nullable', 'boolean'],
            'is_gluten_free' => ['nullable', 'boolean'],
            'is_halal' => ['nullable', 'boolean'],
            'allergens' => ['nullable', 'string'],
            'nutritional_info' => ['nullable', 'string'],
            'ingredients_list' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'max:2048'],

            // ==================== NEW PRICING VALIDATION ====================
            'pricing_type' => ['nullable', 'in:per_pax,per_tray,both'],
            'tray_price' => ['nullable', 'numeric', 'min:0'],
            'tray_servings' => ['nullable', 'integer', 'min:1'],
            'tray_min_pax' => ['nullable', 'integer', 'min:1'],
            'tray_max_pax' => ['nullable', 'integer', 'min:1', 'gte:tray_min_pax'],
            'tray_description' => ['nullable', 'string', 'max:255'],

            'ingredients' => ['nullable', 'array'],
            'ingredients.*.ingredient_id' => ['required', 'integer', 'distinct', 'exists:ingredients,ingredient_id'],
            'ingredients.*.quantity_per_pax' => ['required', 'numeric', 'min:0.001'],
            'ingredients.*.unit' => ['required', 'string', 'max:20'],
        ]);
    }

    private function normalizeIngredients(Request $request): void
    {
        if (!$request->has('ingredients')) {
            return;
        }

        $ingredients = $request->input('ingredients');

        if (is_string($ingredients)) {
            $decoded = json_decode($ingredients, true);

            if (!is_array($decoded)) {
                throw ValidationException::withMessages([
                    'ingredients' => 'Ingredients must be a valid JSON array.',
                ]);
            }

            $request->merge(['ingredients' => $decoded]);
        }
    }

    private function syncIngredients(MenuItem $menuItem, array $ingredients): void
    {
        $menuItem->recipeIngredients()->delete();

        foreach ($ingredients as $ingredient) {
            $menuItem->recipeIngredients()->create([
                'ingredient_id' => $ingredient['ingredient_id'],
                'quantity_per_pax' => $ingredient['quantity_per_pax'],
                'unit' => $ingredient['unit'],
            ]);
        }
    }

    private function recalculateCost(MenuItem $menuItem): void
    {
        $cost = $menuItem->recipeIngredients()
            ->with('ingredient:ingredient_id,unit_cost')
            ->get()
            ->sum(function ($row) {
                return (float) $row->quantity_per_pax * (float) ($row->ingredient?->unit_cost ?? 0);
            });

        $menuItem->update(['cost_to_make' => round($cost, 2)]);
    }

    private function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'menu-item';
        $slug = Str::limit($base, 115, '');
        $counter = 1;

        while (
            MenuItem::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId, fn($query) => $query->where('menu_item_id', '!=', $ignoreId))
            ->exists()
        ) {
            $suffix = '-' . $counter++;
            $slug = Str::limit($base, 120 - strlen($suffix), '') . $suffix;
        }

        return $slug;
    }

    private function ratingStatsForMenuItem(int $menuItemId): array
    {
        $stats = Review::query()
            ->join('booking_items', 'reviews.booking_id', '=', 'booking_items.booking_id')
            ->where('booking_items.menu_item_id', $menuItemId)
            ->where('reviews.is_approved', true)
            ->selectRaw('AVG(reviews.overall_rating) as average, COUNT(reviews.review_id) as count')
            ->first();

        return [
            'average' => round((float) ($stats?->average ?? 0), 1),
            'count' => (int) ($stats?->count ?? 0),
        ];
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 15), 1), 500);
    }

    private function placeholderImageUrl(?string $text = 'No Image'): string
    {
        $label = htmlspecialchars($text ?: 'No Image', ENT_QUOTES, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400" viewBox="0 0 400 400"><rect width="400" height="400" fill="#FF6B9D"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="Arial, sans-serif" font-size="32" fill="#FFFFFF">' . $label . '</text></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
