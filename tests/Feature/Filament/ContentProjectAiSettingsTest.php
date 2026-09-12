<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ContentProjects\Pages\EditContentProject;
use App\Models\ContentProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContentProjectAiSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_ai_settings_preserves_other_settings_keys(): void
    {
        $this->actingAs(User::factory()->create());

        $project = ContentProject::factory()->create([
            'settings' => [
                'tone' => 'fast',
                'target_duration' => 60,
                'ai' => [
                    'default' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
                ],
            ],
        ]);

        Livewire::test(EditContentProject::class, ['record' => $project->getRouteKey()])
            ->fillForm([
                'settings.ai.script.provider' => 'anthropic',
                'settings.ai.script.model' => 'claude-opus-4',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $project->refresh();

        $this->assertSame('fast', $project->settings['tone']);
        $this->assertSame(60, $project->settings['target_duration']);
        $this->assertSame('openai', $project->settings['ai']['default']['provider']);
        $this->assertSame('anthropic', $project->settings['ai']['script']['provider']);
        $this->assertSame('claude-opus-4', $project->settings['ai']['script']['model']);
    }
}
