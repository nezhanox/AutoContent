<?php

namespace Database\Factories;

use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContentIdeaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_project_id' => ContentProject::factory(),
            'title' => fake()->sentence(6),
            'topic' => fake()->words(3, true),
            'source' => fake()->randomElement(['manual', 'trend_scan', 'rss']),
            'source_url' => fake()->optional()->url(),
            'source_data' => null,
            'score' => fake()->optional()->randomFloat(2, 0, 100),
            'status' => ContentIdeaStatus::New,
        ];
    }
}
