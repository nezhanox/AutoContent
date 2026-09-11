<?php

namespace Database\Factories;

use App\Models\Publication;
use Illuminate\Database\Eloquent\Factories\Factory;

class VideoMetricFactory extends Factory
{
    public function definition(): array
    {
        return [
            'publication_id' => Publication::factory(),
            'views' => fake()->numberBetween(0, 100000),
            'likes' => fake()->numberBetween(0, 10000),
            'comments' => fake()->numberBetween(0, 1000),
            'shares' => fake()->numberBetween(0, 500),
            'saves' => fake()->optional()->numberBetween(0, 500),
            'watch_time' => fake()->optional()->numberBetween(1, 60),
            'completion_rate' => fake()->optional()->randomFloat(2, 0, 100),
            'followers_gained' => fake()->optional()->numberBetween(0, 200),
            'metadata' => [],
            'measured_at' => now(),
        ];
    }
}
