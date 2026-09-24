<?php

namespace App\Services;

use App\Models\AiConfiguration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AiSettings
{
    private const CACHE_MINUTES = 15;

    /** OpenAI model families usable for text generation through the Responses API. */
    private const OPENAI_ALLOWED = '/^(gpt-[0-9]|gpt-4|gpt-5|o1|o3|o4|chatgpt-)/i';

    /** Endpoint-specific models that cannot produce study notes. */
    private const OPENAI_EXCLUDED = '/(embedding|moderation|tts|whisper|audio|realtime|image|dall-e|transcribe|search|codex)/i';

    public function current(): AiConfiguration
    {
        return AiConfiguration::find(1) ?? new AiConfiguration(['enabled' => true, 'provider' => config('study.provider'), 'model' => config('study.model'), 'api_key' => config('study.api_key'), 'daily_limit' => config('study.daily_limit'), 'max_input_chars' => config('study.max_input_chars'), 'max_output_tokens' => config('study.max_output_tokens'), 'require_zero_retention' => true, 'version' => 0]);
    }

    /**
     * Selectable models for a provider, as id => display name.
     *
     * OpenRouter returns only zero-price models. OpenAI requires a stored credential and
     * returns an empty list without one, so the administrator saves the key first and then
     * pulls the catalog.
     *
     * @return array<string, string>
     */
    public function models(string $provider, bool $refresh = false): array
    {
        if ($provider === 'mock') {
            return [];
        }
        $key = 'ai.models.'.$provider;
        if (! $refresh && Cache::has($key)) {
            return Cache::get($key);
        }

        $models = $provider === 'openrouter' ? $this->openRouterModels() : $this->openAiModels();

        Cache::put($key, $models, now()->addMinutes(self::CACHE_MINUTES));
        Cache::put($key.'.at', now()->toIso8601String(), now()->addMinutes(self::CACHE_MINUTES));

        return $models;
    }

    public function catalogRefreshedAt(string $provider): ?string
    {
        return Cache::get('ai.models.'.$provider.'.at');
    }

    /**
     * Zero-price OpenRouter models.
     *
     * @return array<string, string>
     */
    public function freeModels(bool $refresh = false): array
    {
        return $this->models('openrouter', $refresh);
    }

    /** @return array<string, string> */
    private function openRouterModels(): array
    {
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
                // Every published price must be exactly zero: never risk a paid selection.
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

            return $models;
        } catch (\Throwable) {
            throw new \RuntimeException('The OpenRouter model catalog is unavailable. Try again later.');
        }
    }

    /** @return array<string, string> */
    private function openAiModels(): array
    {
        $configuration = $this->current();
        if (! $configuration->api_key) {
            throw new \RuntimeException('Save an OpenAI credential before pulling its model catalogue.');
        }
        try {
            $response = Http::withToken($configuration->api_key)->acceptJson()->connectTimeout(5)->timeout(15)
                ->get('https://api.openai.com/v1/models');
            if (! $response->successful() || ! is_array($response->json('data'))) {
                throw new \RuntimeException;
            }
            $models = [];
            foreach ($response->json('data') as $model) {
                $id = $model['id'] ?? null;
                if (! is_string($id) || ! preg_match(self::OPENAI_ALLOWED, $id) || preg_match(self::OPENAI_EXCLUDED, $id)) {
                    continue;
                }
                $models[$id] = $id;
            }
            ksort($models);

            return $models;
        } catch (\Throwable) {
            throw new \RuntimeException('The OpenAI model catalogue is unavailable. Check the stored credential and try again.');
        }
    }
}
