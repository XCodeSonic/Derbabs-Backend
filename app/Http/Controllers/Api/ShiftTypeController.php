<?php

namespace App\Http\Controllers\Api;

use App\Models\ShiftType;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShiftTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = ShiftType::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return $this->ok($query->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);
        $data = $this->toDatabasePayload($validated);

        return $this->ok(ShiftType::create($data), 'Shift type created successfully');
    }

    public function show(ShiftType $shiftType)
    {
        return $this->ok($shiftType);
    }

    public function update(Request $request, ShiftType $shiftType)
    {
        $validated = $this->validatePayload($request, $shiftType);
        $shiftType->update($this->toDatabasePayload($validated, $shiftType));

        return $this->ok($shiftType->fresh(), 'Shift type updated successfully');
    }

    public function destroy(ShiftType $shiftType)
    {
        if ($shiftType->schedules()->exists()) {
            throw ValidationException::withMessages([
                'shift_type' => 'This shift type is already used by schedules. Deactivate it instead of deleting it.',
            ]);
        }

        $shiftType->delete();

        return $this->ok(null, 'Shift type deleted successfully');
    }

    private function validatePayload(Request $request, ?ShiftType $shiftType = null): array
    {
        $id = $shiftType?->shift_type_id;
        $required = $shiftType ? 'sometimes|required' : 'required';

        // Accept the frontend aliases while persisting only migration-backed columns.
        $request->merge([
            'slug' => $request->input('slug', $request->input('code', $shiftType?->slug)),
            'default_start_time' => $request->input('default_start_time', $request->input('start_time', $shiftType?->default_start_time)),
            'default_end_time' => $request->input('default_end_time', $request->input('end_time', $shiftType?->default_end_time)),
        ]);

        return $request->validate([
            'name' => "{$required}|string|max:50|unique:shift_types,name" . ($id ? ",{$id},shift_type_id" : ''),
            'slug' => 'nullable|string|max:50|unique:shift_types,slug' . ($id ? ",{$id},shift_type_id" : ''),
            'code' => 'nullable|string|max:50',
            'default_start_time' => [$required, 'regex:/^([01]\\d|2[0-3]):[0-5]\\d(:[0-5]\\d)?$/'],
            'default_end_time' => [$required, 'regex:/^([01]\\d|2[0-3]):[0-5]\\d(:[0-5]\\d)?$/'],
            'start_time' => ['nullable', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d(:[0-5]\\d)?$/'],
            'end_time' => ['nullable', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d(:[0-5]\\d)?$/'],
            'break_minutes' => 'nullable|numeric|min:0',
            'night_differential_rate' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);
    }

    private function toDatabasePayload(array $validated, ?ShiftType $existing = null): array
    {
        $name = $validated['name'] ?? $existing?->name;
        $slug = $validated['slug'] ?? $validated['code'] ?? $existing?->slug ?? Str::slug((string) $name);

        return array_filter([
            'name' => $name,
            'slug' => Str::slug((string) $slug),
            'default_start_time' => $validated['default_start_time'] ?? $validated['start_time'] ?? $existing?->default_start_time,
            'default_end_time' => $validated['default_end_time'] ?? $validated['end_time'] ?? $existing?->default_end_time,
            'break_minutes' => $validated['break_minutes'] ?? $existing?->break_minutes ?? 60,
            'night_differential_rate' => $validated['night_differential_rate'] ?? $existing?->night_differential_rate ?? 0,
            'is_active' => $validated['is_active'] ?? $existing?->is_active ?? true,
        ], static fn ($value) => $value !== null);
    }
}
