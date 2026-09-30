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
            'face_verified' => 'nullable|boolean',
            'liveness_checked' => 'nullable|boolean',
        ];
    }
}
