<?php

namespace Database\Factories;

use App\Models\Enums\PublicationStatus;
use App\Models\SocialAccount;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

class PublicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'video_id' => Video::factory(),
            'social_account_id' => SocialAccount::factory(),
            'caption' => null,
            'hashtags' => [],
            'scheduled_at' => null,
            'published_at' => null,
            'external_post_id' => null,
            'status' => PublicationStatus::Draft,
            'error_message' => null,
            'metadata' => [],
        ];
    }
}
