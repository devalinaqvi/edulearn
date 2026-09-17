<?php

namespace App\Services;

use App\Models\AiConfiguration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AiSettings
{
    public function current(): AiConfiguration
    {
        return AiConfiguration::find(1) ?? new AiConfiguration(['enabled' => true, 'provider' => config('study.provider'), 'model' => config('study.model'), 'api_key' => config('study.api_key'), 'daily_limit' => config('study.daily_limit'), 'max_input_chars' => config('study.max_input_chars'), 'max_output_tokens' => config('study.max_output_tokens'), 'version' => 0]);
    }

    /** @return array<string, string> */
    public function freeModels(bool $refresh = false): array
    {
        if (! $refresh && Cache::has('ai.openrouter.free_models')) {
            return Cache::get('ai.openrouter.free_models');
        }
        try {
            $response = Http::acceptJson()->connectTimeout(5)->timeout(15)->get('https://openrouter.ai/api/v1/models');
            if (! $response->successful() || ! is_array($response->json('data'))) {
                throw new \RuntimeException;
            }
            $models = [];
            foreach ($response->json('data') as $model) {
                $pricing = $model['pricing'] ?? [];
                if (! isset($model['id'], $model['name'], $pricing['prompt'], $pricing['completion'])) {
                    continue;
                }
                $free = true;
                foreach ($pricing as $price) {
                    if (! is_numeric($price) || (float) $price !== 0.0) {
                        $free = false;
                    }
                }
                if ($free && is_string($model['id']) && is_string($model['name'])) {
                    $models[$model['id']] = $model['name'];
                }
            }
            asort($models);
            Cache::put('ai.openrouter.free_models', $models, now()->addMinutes(15));
            Cache::put('ai.openrouter.catalog_at', now()->toIso8601String(), now()->addMinutes(15));

            return $models;
        } catch (\Throwable) {
            throw new \RuntimeException('The OpenRouter model catalog is unavailable. Try again later.');
        }
    }
}
