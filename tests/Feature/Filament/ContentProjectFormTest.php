<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ContentProjects\Pages\EditContentProject;
use App\Models\ContentProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContentProjectFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_target_platforms_and_default_voice_persists_them(): void
    {
        $this->actingAs(User::factory()->create());

        $project = ContentProject::factory()->create([
            'target_platforms' => [],
            'settings' => [],
        ]);

        Livewire::test(EditContentProject::class, ['record' => $project->getRouteKey()])
            ->fillForm([
                'target_platforms' => ['tiktok', 'youtube'],
                'settings.tts.voice' => 'JBFqnCBsd6RMkjVDRZzb',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $project->fresh();
        $this->assertSame(['tiktok', 'youtube'], $fresh->target_platforms);
        $this->assertSame('JBFqnCBsd6RMkjVDRZzb', $fresh->settings['tts']['voice']);
    }
}
