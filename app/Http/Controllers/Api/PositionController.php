<?php

namespace App\Http\Controllers\Api;

use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PositionController extends Controller
{
    public function index(Request $request)
    {
        $query = Position::query()->with(['department', 'salaryGrade']);

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->input('department_id'));
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        if ($request->boolean('all')) {
            return $this->ok($query->orderBy('title')->get());
        }

        return $this->ok($query->latest('position_id')->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);
        $data = $this->toDatabasePayload($validated);
        $data['code'] = $data['code'] ?? $this->makeUniqueCode($validated['title']);

        $position = Position::create($data);

        return $this->ok($position->load(['department', 'salaryGrade']), 'Position created');
    }

    public function show(Position $position)
    {
        return $this->ok($position->load(['department', 'salaryGrade']));
    }

    public function update(Request $request, Position $position)
    {
        $validated = $this->validatePayload($request, $position);
        $position->update($this->toDatabasePayload($validated));

        return $this->ok($position->fresh(['department', 'salaryGrade']), 'Position updated');
    }

    public function destroy(Position $position)
    {
        if ($position->employees()->exists()) {
            throw ValidationException::withMessages([
                'position' => 'This position is assigned to employees and cannot be deleted.',
            ]);
        }

        $position->delete();

        return $this->ok(null, 'Position deleted');
    }

    private function validatePayload(Request $request, ?Position $position = null): array
    {
        $id = $position?->position_id;
        $required = $position ? 'sometimes|required' : 'required';

        return $request->validate([
            'department_id' => "{$required}|exists:departments,department_id",
            'salary_grade_id' => "{$required}|exists:salary_grades,salary_grade_id",
            'title' => "{$required}|string|max:100",
            'code' => 'nullable|string|max:30|unique:positions,code' . ($id ? ",{$id},position_id" : ''),
            'description' => 'nullable|string',
            'employment_type' => 'nullable|string|max:50',
            'status' => 'nullable|in:active,inactive',
            'is_active' => 'nullable|boolean',
            // Accepted for UI compatibility, but intentionally not persisted because they are absent from the migration.
            'max_hours_per_week' => 'nullable|integer|min:1|max:168',
            'required_skills' => 'nullable',
        ]);
    }

    private function toDatabasePayload(array $validated): array
    {
        $data = collect($validated)->only([
            'department_id', 'salary_grade_id', 'title', 'code', 'description', 'employment_type', 'is_active',
        ])->toArray();

        if (array_key_exists('employment_type', $data)) {
            $normalized = str_replace('-', '_', strtolower((string) $data['employment_type']));
            $data['employment_type'] = match ($normalized) {
                'full_time', 'part_time', 'contract', 'intern', 'temporary' => $normalized,
                'internship' => 'intern',
                default => 'full_time',
            };
        }

        if (array_key_exists('status', $validated)) {
            $data['is_active'] = $validated['status'] === 'active';
        }

        return $data;
    }

    private function makeUniqueCode(string $title): string
    {
        $base = Str::upper(Str::limit(Str::slug($title, '_'), 24, '')) ?: 'POSITION';
        $candidate = $base;
        $counter = 1;

        while (Position::where('code', $candidate)->exists()) {
            $suffix = '_' . $counter++;
            $candidate = Str::limit($base, 30 - strlen($suffix), '') . $suffix;
        }

        return $candidate;
    }
}
