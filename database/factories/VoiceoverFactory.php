<?php

namespace Database\Factories;

use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

class VoiceoverFactory extends Factory
{
    public function definition(): array
    {
        return [
            'video_id' => Video::factory(),
            'provider' => 'elevenlabs',
            'voice' => fake()->randomElement(['adam', 'rachel', 'bella']),
            'text' => fake()->paragraph(),
            'file_path' => null,
            'duration' => null,
            'metadata' => [],
            'status' => 'pending',
        ];
    }
}
