<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $status = $this->input('status');
        if ($status === 'onleave' || $status === 'on-leave') {
            $this->merge(['status' => 'on_leave']);
        }

        foreach (['skills', 'certifications', 'achievements'] as $field) {
            $value = $this->input($field);
            if (! is_string($value)) {
                continue;
            }

            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->merge([$field => $decoded]);
            }
        }
    }

    public function rules(): array
    {
        $employee = $this->route('employee');
        if (! $employee instanceof Employee && $employee) {
            $employee = Employee::find($employee);
        }

        $employeeId = $employee?->employee_id;
        $personId = $employee?->person_id;
        $userId = $employee?->user_id;

        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'email' => [
                'required',
                'email',
                'max:120',
                Rule::unique('persons', 'email')->ignore($personId, 'person_id'),
                Rule::unique('users', 'username')->ignore($userId, 'user_id'),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other', 'prefer_not_to_say'])],
            'address' => ['nullable', 'string'],
            'address_line_1' => ['nullable', 'string'],
            'address_line_2' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'province' => ['nullable', 'string', 'max:80'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:80'],
            'department_id' => ['required', 'integer', 'exists:departments,department_id'],
            'position_id' => ['required', 'integer', 'exists:positions,position_id'],
            'hire_date' => ['required', 'date'],
            'regularization_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'termination_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'status' => ['nullable', Rule::in(['active', 'on_leave', 'inactive', 'terminated'])],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'hourly_rate_override' => ['nullable', 'numeric', 'min:0'],
            'sss_number' => ['nullable', 'string', 'max:50', Rule::unique('employees', 'sss_number')->ignore($employeeId, 'employee_id')],
            'philhealth_number' => ['nullable', 'string', 'max:50', Rule::unique('employees', 'philhealth_number')->ignore($employeeId, 'employee_id')],
            'pagibig_number' => ['nullable', 'string', 'max:50', Rule::unique('employees', 'pagibig_number')->ignore($employeeId, 'employee_id')],
            'tin_number' => ['nullable', 'string', 'max:50', Rule::unique('employees', 'tin_number')->ignore($employeeId, 'employee_id')],
            'sss' => ['nullable', 'string', 'max:50'],
            'philhealth' => ['nullable', 'string', 'max:50'],
            'pagibig' => ['nullable', 'string', 'max:50'],
            'tin' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_profile_photo' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:8'],
            'skills' => ['nullable', 'array'],
            'skills.*' => ['string', 'max:100'],
            'certifications' => ['nullable', 'array'],
            'certifications.*' => ['string', 'max:150'],
            'achievements' => ['nullable', 'array'],
            'achievements.*' => ['string', 'max:150'],
            'employee_type' => ['nullable', 'string', 'max:50'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_account_number' => ['nullable', 'string', 'max:120'],
            'allowances' => ['nullable', 'numeric', 'min:0'],
            'other_deductions' => ['nullable', 'numeric', 'min:0'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:80'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
