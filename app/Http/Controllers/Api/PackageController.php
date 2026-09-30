<?php

namespace App\Http\Controllers\Api;

use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PackageController extends Controller
{
    public function index(Request $request)
    {
        $query = Package::query()
            ->with(['menuItems' => function ($query) {
                $query->select('menu_items.*', 'package_menu_items.*');
            }]);

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        }

        if ($request->has('is_active') && $request->input('is_active') !== '') {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->boolean('is_featured')) {
            $query->where('is_featured', true);
        }

        if ($request->is('api/v1/public/*')) {
            $query->where('is_active', true);
        }

        $query->orderBy('sort_order')->orderBy('name');
        $query->withCount('menuItems as items_count');

        $packages = $query->paginate($this->perPage($request));
        $packages->getCollection()->transform(function ($package) {
            return $this->formatPackage($package);
        });

        return response()->json([
            'success' => true,
            'data' => $packages,
            'message' => 'Packages retrieved successfully'
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $package = DB::transaction(function () use ($validated, $request) {
            $data = $this->preparePackageData($validated);
            $data['slug'] = $this->uniqueSlug($data['name']);

            if ($request->hasFile('image')) {
                $data['image_url'] = $this->storeImage($request);
            }

            $package = Package::create($data);

            if ($request->has('menu_items') && is_array($request->input('menu_items'))) {
                $this->syncMenuItems($package, $request->input('menu_items'));
            }

            return $package;
        });

        return response()->json([
            'success' => true,
            'data' => $this->formatPackage($package->fresh(['menuItems'])),
            'message' => 'Package created successfully'
        ]);
    }

    public function show(Package $package)
    {
        $package->load(['menuItems' => function ($query) {
            $query->select('menu_items.*', 'package_menu_items.*');
        }]);

        return response()->json([
            'success' => true,
            'data' => $this->formatPackage($package),
            'message' => 'Package retrieved successfully'
        ]);
    }

    public function update(Request $request, Package $package)
    {
        $validated = $this->validatePayload($request, $package);

        DB::transaction(function () use ($validated, $package, $request) {
            $data = $this->preparePackageData($validated, true);

            if ($request->has('name') && $request->input('name') !== $package->name) {
                $data['slug'] = $this->uniqueSlug($request->input('name'), $package->package_id);
            }

            if ($request->hasFile('image')) {
                $this->deleteStoredImage($package->image_url);
                $data['image_url'] = $this->storeImage($request);
            }

            $package->update($data);

            if ($request->has('menu_items') && is_array($request->input('menu_items'))) {
                $this->syncMenuItems($package, $request->input('menu_items'));
            }
        });

        return response()->json([
            'success' => true,
            'data' => $this->formatPackage($package->fresh(['menuItems'])),
            'message' => 'Package updated successfully'
        ]);
    }

    public function destroy(Package $package)
    {
        $this->deleteStoredImage($package->image_url);
        $package->menuItems()->detach();
        $package->delete();

        return response()->json([
            'success' => true,
            'message' => 'Package deleted successfully'
        ]);
    }

    public function restore($id)
    {
        $package = Package::onlyTrashed()->findOrFail($id);
        $package->restore();

        return response()->json([
            'success' => true,
            'data' => $this->formatPackage($package->fresh(['menuItems'])),
            'message' => 'Package restored successfully'
        ]);
    }

private function formatPackage(Package $package): array
{
    // Get the full image URL
    $packageImage = $this->getFullImageUrl($package->image_url, $package->name);

    $menuItems = $package->menuItems->map(function ($item) use ($packageImage) {
        $imageUrl = $this->getFullImageUrl($item->image_url, $item->name);

        return [
            'id' => $item->menu_item_id,
            'menu_item_id' => $item->menu_item_id,
            'name' => $item->name,
            'price' => (float) $item->price,
            'image_url' => $imageUrl,
            'image' => $imageUrl,
            'quantity_per_pax' => (int) $item->pivot->quantity_per_pax,
            'quantity' => (int) $item->pivot->quantity_per_pax,
            'is_optional' => (bool) $item->pivot->is_optional,
            'is_replaceable' => (bool) $item->pivot->is_replaceable,
            'additional_cost' => (float) $item->pivot->additional_cost,
        ];
    });

    return [
        'id' => $package->package_id,
        'package_id' => $package->package_id,
        'name' => $package->name,
        'slug' => $package->slug,
        'description' => $package->description,
        'base_price_per_pax' => (float) $package->base_price_per_pax,
        'price_per_additional_pax' => (float) $package->price_per_additional_pax,
        'min_pax' => (int) $package->min_pax,
        'max_pax' => (int) $package->max_pax,
        'default_duration_hours' => (int) $package->default_duration_hours,
        'inclusions' => $this->parseJson($package->inclusions),
        'exclusions' => $this->parseJson($package->exclusions),
        'sort_order' => (int) $package->sort_order,
        'is_active' => (bool) $package->is_active,
        'is_featured' => (bool) $package->is_featured,
        'items_count' => $package->menuItems->count(),
        'image_url' => $packageImage,
        'image' => $packageImage,
        'menu_items' => $menuItems,
        'items' => $menuItems,
        'created_at' => $package->created_at,
        'updated_at' => $package->updated_at,
    ];
}

    private function getFullImageUrl($imagePath, $itemName): string
    {
        if (empty($imagePath)) {
            return $this->placeholderImageUrl($itemName);
        }

        if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
            return $imagePath;
        }

        if (str_starts_with($imagePath, '/storage/')) {
            return url($imagePath);
        }

        if (Storage::disk('public')->exists($imagePath)) {
            return Storage::disk('public')->url($imagePath);
        }

        $urlPath = url('storage/' . $imagePath);
        if (filter_var($urlPath, FILTER_VALIDATE_URL)) {
            return $urlPath;
        }

        return $this->placeholderImageUrl($itemName);
    }

    private function storeImage(Request $request): string
    {
        return $request->file('image')->store('packages', 'public');
    }

    private function deleteStoredImage($imagePath): void
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

    private function validatePayload(Request $request, $package = null): array
    {
        $required = $package ? 'sometimes' : 'required';
        $packageId = $package?->package_id;

        return $request->validate([
            'name' => [$required, 'string', 'max:100'],
            'slug' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('packages', 'slug')->ignore($packageId, 'package_id'),
            ],
            'description' => ['nullable', 'string'],
            'base_price_per_pax' => [$required, 'numeric', 'min:0'],
            'price_per_additional_pax' => ['nullable', 'numeric', 'min:0'],
            'min_pax' => ['nullable', 'integer', 'min:1'],
            'max_pax' => ['nullable', 'integer', 'min:1'],
            'default_duration_hours' => ['nullable', 'integer', 'min:1'],
            'inclusions' => ['nullable', 'array'],
            'exclusions' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'max:2048'],
            'menu_items' => ['nullable', 'array'],
            'menu_items.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,menu_item_id'],
            'menu_items.*.quantity_per_pax' => ['nullable', 'integer', 'min:1'],
            'menu_items.*.is_optional' => ['nullable', 'boolean'],
            'menu_items.*.is_replaceable' => ['nullable', 'boolean'],
            'menu_items.*.additional_cost' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    private function preparePackageData(array $data, bool $partial = false): array
    {
        $defaults = [
            'name' => '',
            'slug' => '',
            'description' => null,
            'base_price_per_pax' => 0,
            'price_per_additional_pax' => 0,
            'min_pax' => 1,
            'max_pax' => 100,
            'default_duration_hours' => 4,
            'sort_order' => 0,
            'is_active' => true,
            'is_featured' => false,
        ];

        $packageData = [];

        foreach ($defaults as $field => $default) {
            if (array_key_exists($field, $data)) {
                $packageData[$field] = $data[$field];
            } elseif (!$partial) {
                $packageData[$field] = $default;
            }
        }

        if (array_key_exists('inclusions', $data)) {
            $packageData['inclusions'] = is_array($data['inclusions'])
                ? json_encode($data['inclusions'])
                : $data['inclusions'];
        }

        if (array_key_exists('exclusions', $data)) {
            $packageData['exclusions'] = is_array($data['exclusions'])
                ? json_encode($data['exclusions'])
                : $data['exclusions'];
        }

        return $packageData;
    }

    private function syncMenuItems(Package $package, array $menuItems): void
    {
        $syncData = [];

        foreach ($menuItems as $item) {
            $menuItemId = $item['menu_item_id'] ?? $item['id'] ?? null;
            
            if (!$menuItemId) {
                continue;
            }

            $syncData[$menuItemId] = [
                'quantity_per_pax' => $item['quantity_per_pax'] ?? $item['quantity'] ?? 1,
                'is_optional' => (bool) ($item['is_optional'] ?? false),
                'is_replaceable' => (bool) ($item['is_replaceable'] ?? false),
                'additional_cost' => $item['additional_cost'] ?? 0,
            ];
        }

        $package->menuItems()->sync($syncData);
    }

    private function parseJson($value)
    {
        if (is_null($value)) {
            return [];
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'package';
        $slug = Str::limit($base, 115, '');
        $counter = 1;

        while (
            Package::withTrashed()
                ->where('slug', $slug)
                ->when($ignoreId, fn($query) => $query->where('package_id', '!=', $ignoreId))
                ->exists()
        ) {
            $suffix = '-' . $counter++;
            $slug = Str::limit($base, 120 - strlen($suffix), '') . $suffix;
        }

        return $slug;
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