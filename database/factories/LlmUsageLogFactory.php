<?php

namespace Database\Factories;

use App\Models\ContentProject;
use App\Models\Enums\LlmUsageLogStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class LlmUsageLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_project_id' => ContentProject::factory(),
            'purpose' => fake()->randomElement(['script', 'idea', 'quality_check', 'captions']),
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'prompt_tokens' => fake()->numberBetween(50, 2000),
            'completion_tokens' => fake()->numberBetween(50, 2000),
            'cost' => fake()->randomFloat(6, 0.0001, 0.5),
            'duration_ms' => fake()->numberBetween(200, 5000),
            'status' => LlmUsageLogStatus::Success,
            'error_message' => null,
            'metadata' => [],
        ];
    }
}
