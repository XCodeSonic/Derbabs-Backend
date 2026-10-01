<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => 'nullable',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'selfie' => 'nullable|string',
            'device_info' => 'nullable|string|max:255',
            'captured_at' => 'nullable|date',
            'timestamp' => 'nullable|date',
            // ⭐ FIX D: Accept the device timezone so we can store the correct date.
            'timezone' => 'nullable|string|max:64',
            // ⭐ FIX: Optional cutoff hint from the mobile app so the row never
            //    drifts into a neighboring cutoff when timezone conversion runs.
            'cutoff_start' => 'nullable|date',
            'face_verified' => 'nullable|boolean',
            'liveness_checked' => 'nullable|boolean',
        ];
    }
}
