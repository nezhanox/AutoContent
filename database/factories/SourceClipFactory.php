<?php

namespace Database\Factories;

use App\Models\SourceVideo;
use Illuminate\Database\Eloquent\Factories\Factory;

class SourceClipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_video_id' => SourceVideo::factory(),
            'start' => 10.0,
            'end' => 70.0,
            'title' => fake()->sentence(5),
        ];
    }
}
