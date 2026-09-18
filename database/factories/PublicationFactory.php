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
            // A publication's social account must belong to the same content project as its
            // video (PublicationForm scopes the account select accordingly) — resolve the
            // account against whichever video_id ends up on the record, default or overridden.
            'social_account_id' => function (array $attributes) {
                return SocialAccount::factory()->create([
                    'content_project_id' => Video::find($attributes['video_id'])?->content_project_id,
                ])->id;
            },
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
