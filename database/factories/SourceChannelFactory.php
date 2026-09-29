<?php

namespace Database\Factories;

use App\Models\ContentProject;
use App\Models\Enums\SourceChannelMode;
use Illuminate\Database\Eloquent\Factories\Factory;

class SourceChannelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_project_id' => ContentProject::factory(),
            'url' => 'https://www.youtube.com/@'.fake()->userName(),
            'mode' => SourceChannelMode::Highlights,
        ];
    }
}
