<?php

namespace App\Http\Controllers\Api;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    /**
     * Generate sequential supplier code (SUP-0013 format)
     */
    private function generateSupplierCode(): string
    {
        return $this->generateSequentialNumber('SUP-', Supplier::class, 'code');
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
            return $prefix . now()->format('YmdHis') . '-' . strtoupper(substr(uniqid(), -4));
        }
    }

    public function index(Request $request)
    {
        $query = Supplier::query();

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('contact_email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('active') || $request->has('is_active')) {
            $isActive = $request->boolean($request->has('active') ? 'active' : 'is_active');
            $query->where('status', $isActive ? 'active' : 'inactive');
        }

        $rows = $query->latest('supplier_id')->paginate(min(max($request->integer('per_page', 15), 1), 500));
        $rows->getCollection()->transform(fn (Supplier $supplier) => $this->formatSupplier($supplier));

        return $this->ok($rows);
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        $data['code'] = $data['code'] ?? $this->generateSupplierCode();
        $data['status'] = $data['status'] ?? (($data['active'] ?? true) ? 'active' : 'inactive');
        unset($data['active'], $data['is_active']);

        $supplier = Supplier::create($data);
        return $this->ok($this->formatSupplier($supplier), 'Supplier created');
    }

    public function show(Supplier $supplier)
    {
        return $this->ok($this->formatSupplier($supplier));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $data = $this->validatePayload($request, $supplier);
        if (array_key_exists('active', $data) || array_key_exists('is_active', $data)) {
            $data['status'] = ($data['active'] ?? $data['is_active']) ? 'active' : 'inactive';
        }
        unset($data['active'], $data['is_active']);

        $supplier->update($data);
        return $this->ok($this->formatSupplier($supplier->fresh()), 'Supplier updated');
    }

    public function destroy(Supplier $supplier)
    {
        $supplier->delete();
        return $this->ok(null, 'Supplier deleted');
    }

    private function validatePayload(Request $request, ?Supplier $supplier = null): array
    {
        $required = $supplier ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('suppliers', 'code')->ignore($supplier?->supplier_id, 'supplier_id')],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'active' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function formatSupplier(Supplier $supplier): array
    {
        return [
            'id' => $supplier->supplier_id,
            'supplier_id' => $supplier->supplier_id,
            'display_id' => sprintf('SUP-%03d', $supplier->supplier_id),
            'name' => $supplier->name,
            'code' => $supplier->code,
            'contact_person' => $supplier->contact_person,
            'contact' => $supplier->contact_person,
            'contact_phone' => $supplier->contact_phone,
            'phone' => $supplier->contact_phone,
            'contact_email' => $supplier->contact_email,
            'email' => $supplier->contact_email,
            'address' => $supplier->address,
            'status' => $supplier->status ?: 'active',
            'active' => ($supplier->status ?: 'active') === 'active',
            'created_at' => $supplier->created_at,
            'updated_at' => $supplier->updated_at,
        ];
    }
}
