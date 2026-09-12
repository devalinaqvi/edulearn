<?php

namespace App\Http\Requests;

use App\Rules\StudyMaterialFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UploadMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', $this->route('course'));
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:160',
            'lesson_id' => ['nullable', Rule::exists('lessons', 'id')->where('course_id', $this->route('course')->id)],
            'file' => ['bail', 'required', 'file', 'max:10240', new StudyMaterialFile],
        ];
    }
}
