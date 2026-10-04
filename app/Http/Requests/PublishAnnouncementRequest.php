<?php

namespace App\Http\Requests;

use App\Services\DisplayTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PublishAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('course') ? Gate::allows('manage', $this->route('course')) : $this->user()?->role === 'admin';
    }

    protected function prepareForValidation(): void
    {
        // Staff type the publication time in the display timezone; store and compare it in UTC.
        $this->merge(DisplayTime::normalize($this->only('publish_at'), 'publish_at'));
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:160',
            'body' => 'required|string|max:10000',
            'state' => 'nullable|in:now,schedule,draft',
            'publish_at' => ['nullable', 'date', Rule::requiredIf($this->input('state') === 'schedule'), 'after:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'publish_at.after' => 'A scheduled time must be in the future. Choose "Publish now" to release it immediately.',
            'publish_at.required' => 'Choose when this announcement should be published.',
        ];
    }

    /** When this announcement becomes visible: null for a draft, now, or a future instant. */
    public function publicationTime(): ?Carbon
    {
        return match ($this->input('state', 'now')) {
            'draft' => null,
            'schedule' => Carbon::parse($this->validated('publish_at')),
            default => now(),
        };
    }
}
