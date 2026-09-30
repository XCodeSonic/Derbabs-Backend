<?php

namespace App\Http\Controllers\Api;

use App\Models\SalaryGrade;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SalaryGradeController extends Controller
{
    public function index(Request $request)
    {
        $query = SalaryGrade::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('grade_name', 'like', "%{$search}%")
                    ->orWhere('grade_code', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('all')) {
            return $this->ok($query->orderBy('grade_name')->get());
        }

        return $this->ok($query->latest('salary_grade_id')->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'grade_name' => 'required|string|max:50|unique:salary_grades,grade_name',
            'grade_code' => 'nullable|string|max:20|unique:salary_grades,grade_code',
            'hourly_rate' => 'nullable|numeric|min:0',
            'default_hourly_rate' => 'nullable|numeric|min:0',
            'min_hourly_rate' => 'nullable|numeric|min:0',
            'max_hourly_rate' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'status' => 'nullable|string|max:30',
            'is_active' => 'nullable|boolean',
        ]);

        $rate = $validated['default_hourly_rate'] ?? $validated['hourly_rate'] ?? 0;

        $data = [
            'grade_name' => $validated['grade_name'],
            'grade_code' => $validated['grade_code'] ?? Str::upper(Str::slug($validated['grade_name'], '_')),
            'min_hourly_rate' => $validated['min_hourly_rate'] ?? $rate,
            'max_hourly_rate' => $validated['max_hourly_rate'] ?? $rate,
            'default_hourly_rate' => $rate,
            'description' => $validated['description'] ?? null,
            'is_active' => isset($validated['status']) ? $validated['status'] === 'active' : ($validated['is_active'] ?? true),
        ];

        $grade = SalaryGrade::create($data);

        return $this->ok($grade, 'Salary grade created');
    }

    public function show(SalaryGrade $salaryGrade)
    {
        return $this->ok($salaryGrade);
    }

    public function update(Request $request, SalaryGrade $salaryGrade)
    {
        $validated = $request->validate([
            'grade_name' => 'sometimes|required|string|max:50|unique:salary_grades,grade_name,' . $salaryGrade->salary_grade_id . ',salary_grade_id',
            'grade_code' => 'nullable|string|max:20|unique:salary_grades,grade_code,' . $salaryGrade->salary_grade_id . ',salary_grade_id',
            'hourly_rate' => 'nullable|numeric|min:0',
            'default_hourly_rate' => 'nullable|numeric|min:0',
            'min_hourly_rate' => 'nullable|numeric|min:0',
            'max_hourly_rate' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'status' => 'nullable|string|max:30',
            'is_active' => 'nullable|boolean',
        ]);

        $data = [];

        foreach (['grade_name', 'grade_code', 'description'] as $field) {
            if (array_key_exists($field, $validated)) {
                $data[$field] = $validated[$field];
            }
        }

        $rate = $validated['default_hourly_rate'] ?? $validated['hourly_rate'] ?? null;

        if ($rate !== null) {
            $data['default_hourly_rate'] = $rate;
            $data['min_hourly_rate'] = $validated['min_hourly_rate'] ?? $rate;
            $data['max_hourly_rate'] = $validated['max_hourly_rate'] ?? $rate;
        }

        if (array_key_exists('status', $validated)) {
            $data['is_active'] = $validated['status'] === 'active';
        } elseif (array_key_exists('is_active', $validated)) {
            $data['is_active'] = $validated['is_active'];
        }

        $salaryGrade->update($data);

        return $this->ok($salaryGrade->fresh(), 'Salary grade updated');
    }

    public function destroy(SalaryGrade $salaryGrade)
    {
        $salaryGrade->delete();

        return $this->ok(null, 'Salary grade deleted');
    }
}
