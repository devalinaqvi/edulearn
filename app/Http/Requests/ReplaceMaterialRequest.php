<?php

namespace App\Http\Requests;

use App\Rules\StudyMaterialFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ReplaceMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', $this->route('material')->course);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:160',
            'version' => 'required|integer|min:0',
            'reason' => 'required|string|max:1000',
            'file' => ['bail', 'required', 'file', 'max:10240', new StudyMaterialFile],
        ];
    }
}
