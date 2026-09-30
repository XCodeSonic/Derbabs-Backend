<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'nullable|string',
            'username' => 'nullable|string',
            'userId' => 'nullable|string',
            'user_id' => 'nullable|string',
            'emailOrUsername' => 'nullable|string',
            'password' => 'required|string',
            'role' => 'nullable|string|in:customer,employee,admin',
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => 'Password is required',
            'role.in' => 'Invalid role specified',
        ];
    }
}