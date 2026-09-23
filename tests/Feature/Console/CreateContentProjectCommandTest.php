<?php

namespace Tests\Feature\Console;

use App\Models\ContentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CreateContentProjectCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_project_with_full_settings(): void
    {
        $exitCode = Artisan::call('content-project:create', [
            'name' => 'Stoic Wisdom',
            '--slug' => 'stoic-wisdom',
            '--niche' => 'philosophy',
            '--language' => 'en',
            '--platform' => ['tiktok', 'youtube'],
            '--tone' => 'calm',
            '--style' => 'narrative',
            '--voice' => 'voice-123',
            '--ai' => ['script:openai:gpt-4o-mini'],
        ]);

        $this->assertSame(0, $exitCode);

        $project = ContentProject::where('slug', 'stoic-wisdom')->sole();

        $this->assertSame('Stoic Wisdom', $project->name);
        $this->assertSame('philosophy', $project->niche);
        $this->assertSame('en', $project->language);
        $this->assertSame(['tiktok', 'youtube'], $project->target_platforms);
        $this->assertSame('active', $project->status);

        // Assert settings structure (order-insensitive due to jsonb)
        $this->assertSame('calm', $project->settings['tone']);
        $this->assertSame('narrative', $project->settings['style']);
        $this->assertSame(['voice' => 'voice-123'], $project->settings['tts']);
        $this->assertSame('openai', $project->settings['ai']['script']['provider']);
        $this->assertSame('gpt-4o-mini', $project->settings['ai']['script']['model']);
    }

    public function test_it_defaults_the_slug_from_the_name(): void
    {
        Artisan::call('content-project:create', [
            'name' => 'Auto Slug Project',
            '--niche' => 'tech',
            '--language' => 'en',
            '--platform' => ['tiktok'],
        ]);

        $this->assertDatabaseHas('content_projects', ['slug' => 'auto-slug-project']);
    }

    public function test_it_fails_without_required_fields(): void
    {
        $exitCode = Artisan::call('content-project:create', [
            'name' => 'Missing Fields',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('content_projects', 0);
    }

    public function test_it_rejects_an_unknown_platform(): void
    {
        $exitCode = Artisan::call('content-project:create', [
            'name' => 'Bad Platform',
            '--niche' => 'tech',
            '--language' => 'en',
            '--platform' => ['myspace'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('content_projects', 0);
    }

    public function test_it_rejects_a_malformed_ai_option(): void
    {
        $exitCode = Artisan::call('content-project:create', [
            'name' => 'Bad Ai Option',
            '--niche' => 'tech',
            '--language' => 'en',
            '--platform' => ['tiktok'],
            '--ai' => ['script-openai-gpt'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('content_projects', 0);
    }
}
