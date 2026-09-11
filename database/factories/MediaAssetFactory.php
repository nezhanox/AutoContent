<?php

namespace Database\Factories;

use App\Models\Enums\MediaAssetType;
use Illuminate\Database\Eloquent\Factories\Factory;

class MediaAssetFactory extends Factory
{
    public function definition(): array
    {
        $type = fake()->randomElement(MediaAssetType::cases());

        return [
            'type' => $type,
            'provider' => 'local',
            'path' => 'assets/'.fake()->uuid().'.'.($type === MediaAssetType::Audio ? 'mp3' : 'mp4'),
            'mime_type' => $type === MediaAssetType::Audio ? 'audio/mpeg' : 'video/mp4',
            'width' => in_array($type, [MediaAssetType::Video, MediaAssetType::Image, MediaAssetType::ScreenRecording, MediaAssetType::Thumbnail], true) ? 1080 : null,
            'height' => in_array($type, [MediaAssetType::Video, MediaAssetType::Image, MediaAssetType::ScreenRecording, MediaAssetType::Thumbnail], true) ? 1920 : null,
            'duration' => in_array($type, [MediaAssetType::Video, MediaAssetType::Audio, MediaAssetType::ScreenRecording], true) ? fake()->numberBetween(3, 30) : null,
            'metadata' => [],
            'hash' => fake()->sha256(),
        ];
    }
}
