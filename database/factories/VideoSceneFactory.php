<?php

namespace Database\Factories;

use App\Models\Enums\VideoSceneType;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

class VideoSceneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'video_id' => Video::factory(),
            'order' => fake()->numberBetween(0, 10),
            'type' => fake()->randomElement(VideoSceneType::cases()),
            'duration' => fake()->numberBetween(2, 6),
            'text' => fake()->sentence(),
            'visual_query' => fake()->optional()->words(3, true),
            'asset_id' => null,
            'start_time' => null,
            'end_time' => null,
            'metadata' => [],
        ];
    }
}
