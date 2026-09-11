<?php

namespace Database\Factories;

use App\Models\ContentIdea;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScriptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_idea_id' => ContentIdea::factory(),
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'prompt_version' => 'v1',
            'content' => fake()->paragraphs(3, true),
            'hook' => fake()->sentence(),
            'estimated_duration' => fake()->numberBetween(30, 90),
            'metadata' => [],
            'status' => 'completed',
        ];
    }
}
