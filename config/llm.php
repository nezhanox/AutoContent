<?php

use App\Domain\Llm\Providers\AnthropicLlmProvider;
use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Domain\Llm\Providers\OpenAiLlmProvider;

return [
    'default_provider' => env('LLM_DEFAULT_PROVIDER', 'openai'),
    'default_model' => env('LLM_DEFAULT_MODEL', 'gpt-4o-mini'),

    'providers' => [
        'openai' => [
            'driver' => OpenAiLlmProvider::class,
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'models' => [
                'gpt-4o' => ['input_cost_per_1k' => 0.0025, 'output_cost_per_1k' => 0.01],
                'gpt-4o-mini' => ['input_cost_per_1k' => 0.00015, 'output_cost_per_1k' => 0.0006],
            ],
        ],

        'anthropic' => [
            'driver' => AnthropicLlmProvider::class,
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'models' => [
                'claude-opus-4' => ['input_cost_per_1k' => 0.015, 'output_cost_per_1k' => 0.075],
                'claude-haiku-4.5' => ['input_cost_per_1k' => 0.001, 'output_cost_per_1k' => 0.005],
            ],
        ],

        'fake' => [
            'driver' => FakeLlmProvider::class,
            'models' => [
                'fake-model' => ['input_cost_per_1k' => 0, 'output_cost_per_1k' => 0],
            ],
        ],
    ],
];
