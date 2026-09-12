<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return ['name' => 'required|string|max:100', 'email' => 'required|email|max:255|unique:users', 'role' => ['required', Rule::in(['admin', 'instructor', 'student'])], 'password' => ['required', 'confirmed', Password::min(10)], 'reason' => 'required|string|max:1000'];
    }
}
