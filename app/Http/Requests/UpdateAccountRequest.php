<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return ['name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user'))], 'role' => ['required', Rule::in(['admin', 'instructor', 'student'])], 'is_active' => 'required|boolean', 'version' => 'required|integer|min:0', 'reason' => 'required|string|max:1000'];
    }
}
