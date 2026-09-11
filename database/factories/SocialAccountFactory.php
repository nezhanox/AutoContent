<?php

namespace Database\Factories;

use App\Models\ContentProject;
use App\Models\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Factories\Factory;

class SocialAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_project_id' => ContentProject::factory(),
            'platform' => fake()->randomElement(SocialPlatform::cases()),
            'external_account_id' => fake()->uuid(),
            'username' => fake()->userName(),
            'access_token' => fake()->sha256(),
            'refresh_token' => fake()->optional()->sha256(),
            'token_expires_at' => fake()->optional()->dateTimeBetween('now', '+30 days'),
            'metadata' => [],
            'status' => 'active',
        ];
    }
}
