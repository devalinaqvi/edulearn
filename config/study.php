<?php

return [
    'provider' => env('AI_PROVIDER', 'mock'),
    'api_key' => env('AI_PROVIDER', 'mock') === 'openrouter' ? env('OPENROUTER_API_KEY') : env('OPENAI_API_KEY'),
    'model' => env('AI_PROVIDER', 'mock') === 'openrouter' ? env('OPENROUTER_MODEL') : env('OPENAI_MODEL', 'gpt-4.1-mini'),
    'max_input_chars' => 12000,
    'max_output_tokens' => 1200,
    'daily_limit' => (int) env('AI_DAILY_LIMIT', 10),
];
