<?php

namespace App\Http\Controllers\Api;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Department::query()->withCount('employees');

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        if ($request->boolean('all')) {
            return $this->ok($query->orderBy('name')->get());
        }

        return $this->ok($query->latest('department_id')->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:departments,name',
            'code' => 'nullable|string|max:20|unique:departments,code',
            'description' => 'nullable|string',
            'manager_id' => 'nullable|exists:users,user_id',
            'status' => 'nullable|in:active,inactive',
            'is_active' => 'nullable|boolean',
        ]);

        $data = $this->toDatabasePayload($validated);
        $data['code'] = $data['code'] ?? $this->makeUniqueCode($validated['name']);

        return $this->ok(Department::create($data), 'Department created');
    }

    public function show(Department $department)
    {
        return $this->ok($department->load('positions'));
    }

    public function update(Request $request, Department $department)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100|unique:departments,name,' . $department->department_id . ',department_id',
            'code' => 'nullable|string|max:20|unique:departments,code,' . $department->department_id . ',department_id',
            'description' => 'nullable|string',
            'manager_id' => 'nullable|exists:users,user_id',
            'status' => 'nullable|in:active,inactive',
            'is_active' => 'nullable|boolean',
        ]);

        $department->update($this->toDatabasePayload($validated));

        return $this->ok($department->fresh(), 'Department updated');
    }

    public function destroy(Department $department)
    {
        if ($department->employees()->exists() || $department->positions()->exists()) {
            throw ValidationException::withMessages([
                'department' => 'This department is still used by employees or positions and cannot be deleted.',
            ]);
        }

        $department->delete();

        return $this->ok(null, 'Department deleted');
    }

    private function toDatabasePayload(array $validated): array
    {
        $data = collect($validated)->only([
            'name', 'code', 'description', 'manager_id', 'is_active',
        ])->toArray();

        if (array_key_exists('status', $validated)) {
            $data['is_active'] = $validated['status'] === 'active';
        }

        return $data;
    }

    private function makeUniqueCode(string $name): string
    {
        $base = Str::upper(Str::limit(Str::slug($name, ''), 16, '')) ?: 'DEPT';
        $candidate = $base;
        $counter = 1;

        while (Department::where('code', $candidate)->exists()) {
            $suffix = (string) $counter++;
            $candidate = Str::limit($base, 20 - strlen($suffix), '') . $suffix;
        }

        return $candidate;
    }
}
