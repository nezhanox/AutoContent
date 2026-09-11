<?php

namespace Database\Factories;

use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\VideoStatus;
use App\Models\Script;
use Illuminate\Database\Eloquent\Factories\Factory;

class VideoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_project_id' => ContentProject::factory(),
            'content_idea_id' => ContentIdea::factory(),
            'script_id' => Script::factory(),
            'title' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'status' => VideoStatus::Draft,
            'duration' => null,
            'width' => null,
            'height' => null,
            'file_path' => null,
            'thumbnail_path' => null,
            'metadata' => [],
            'error_message' => null,
        ];
    }
}
