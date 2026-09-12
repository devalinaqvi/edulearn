<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $course = $this->route('course');

        return $course ? Gate::allows('manage', $course) : in_array($this->user()?->role, ['admin', 'instructor'], true);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('courses')->ignore($this->route('course'))],
            'title' => 'required|string|max:160',
            'description' => 'required|string|max:10000',
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'instructor_id' => $this->user()->role === 'admin' ? ['required', Rule::exists('users', 'id')->where('role', 'instructor')->where('is_active', true)] : ['prohibited'],
        ];
    }
}
