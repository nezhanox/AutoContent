<?php

namespace Database\Factories;

use App\Models\Enums\SourceVideoStatus;
use App\Models\SourceChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

class SourceVideoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_channel_id' => SourceChannel::factory(),
            'youtube_id' => fake()->unique()->regexify('[A-Za-z0-9_-]{11}'),
            'title' => fake()->sentence(6),
            'duration' => 600,
            'status' => SourceVideoStatus::Discovered,
        ];
    }
}
