<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAiConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return ['enabled' => 'required|boolean', 'provider' => 'required|in:mock,openai,openrouter', 'model' => 'nullable|required_unless:provider,mock|string|max:200', 'api_key' => 'nullable|string|max:4096', 'remove_key' => 'sometimes|boolean', 'require_zero_retention' => 'sometimes|boolean', 'daily_limit' => 'required|integer|min:1|max:100', 'max_input_chars' => 'required|integer|min:1000|max:40000', 'max_output_tokens' => 'required|integer|min:256|max:4000', 'version' => 'required|integer|min:0', 'allow_paid' => $this->input('provider') === 'openai' && $this->boolean('enabled') ? 'accepted' : 'sometimes|boolean'];
    }
}
