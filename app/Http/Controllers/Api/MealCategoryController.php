<?php

namespace App\Http\Controllers\Api;

use App\Models\MealCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MealCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = MealCategory::query()->withCount('menuItems');

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active') && $request->input('is_active') !== '') {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // For public routes, only show active categories
        if ($request->is('api/v1/public/*')) {
            $query->where('is_active', true);
        }

        $query->orderBy('display_order')->orderBy('name');

        $isManagementRequest = $request->boolean('manage')
            || $request->is('api/v1/meal-categories/manage');

        if ($isManagementRequest) {
            return $this->ok($query->paginate($this->perPage($request)));
        }

        return $this->ok($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:meal_categories,name'],
            'slug' => ['nullable', 'string', 'max:60', 'unique:meal_categories,slug'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:60'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category = DB::transaction(function () use ($validated) {
            $validated['slug'] = $this->uniqueSlug(
                $validated['slug'] ?? $validated['name']
            );
            $validated['display_order'] = $validated['display_order']
                ?? $validated['sort_order']
                ?? 0;
            $validated['is_active'] = $validated['is_active'] ?? true;
            unset($validated['sort_order']);

            return MealCategory::create($validated);
        });

        return $this->ok($category->loadCount('menuItems'), 'Category created successfully');
    }

    public function show(MealCategory $mealCategory)
    {
        return $this->ok($mealCategory->loadCount('menuItems'));
    }

    public function update(Request $request, MealCategory $mealCategory)
    {
        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('meal_categories', 'name')
                    ->ignore($mealCategory->category_id, 'category_id'),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:60',
                Rule::unique('meal_categories', 'slug')
                    ->ignore($mealCategory->category_id, 'category_id'),
            ],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:60'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($validated, $mealCategory, $request) {
            if ($request->has('slug') || $request->has('name')) {
                $validated['slug'] = $this->uniqueSlug(
                    $validated['slug'] ?? $validated['name'] ?? $mealCategory->name,
                    $mealCategory->category_id
                );
            }

            if (array_key_exists('sort_order', $validated)) {
                $validated['display_order'] = $validated['sort_order'];
            }
            unset($validated['sort_order']);

            $mealCategory->update($validated);
        });

        return $this->ok($mealCategory->fresh()->loadCount('menuItems'), 'Category updated successfully');
    }

    public function destroy(MealCategory $mealCategory)
    {
        if ($mealCategory->menuItems()->exists()) {
            return $this->error('Cannot delete a category that still has menu items.', 422);
        }

        $mealCategory->delete();

        return $this->ok(null, 'Category deleted successfully');
    }

    // ==================== HELPER METHODS ====================

    private function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'category';
        $slug = Str::limit($base, 55, '');
        $counter = 1;

        while (
            MealCategory::withTrashed()
                ->where('slug', $slug)
                ->when($ignoreId, fn($query) => $query->where('category_id', '!=', $ignoreId))
                ->exists()
        ) {
            $suffix = '-' . $counter++;
            $slug = Str::limit($base, 60 - strlen($suffix), '') . $suffix;
        }

        return $slug;
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 15), 1), 500);
    }
}