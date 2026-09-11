<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ContentProjectFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 100000),
            'description' => fake()->sentence(),
            'niche' => fake()->randomElement(['tech_news', 'fitness', 'finance', 'gaming']),
            'language' => fake()->randomElement(['en', 'uk', 'es']),
            'target_platforms' => fake()->randomElements(['tiktok', 'youtube', 'instagram', 'x'], 2),
            'status' => 'active',
            'settings' => [
                'tone' => 'fast',
                'target_duration' => 60,
                'ai' => [
                    'default' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
                ],
            ],
        ];
    }
}
