<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class PublishAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('course') ? Gate::allows('manage', $this->route('course')) : $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return ['title' => 'required|string|max:160', 'body' => 'required|string|max:10000'];
    }
}
