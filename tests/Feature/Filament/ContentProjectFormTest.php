<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ContentProjects\Pages\EditContentProject;
use App\Models\ContentProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ContentProjectFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('tts.providers.elevenlabs.api_key', 'test-key');
    }

    public function test_the_default_voice_options_are_loaded_from_elevenlabs(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/voices' => Http::response([
                'voices' => [
                    ['voice_id' => 'newVoiceId123', 'name' => 'Did Vishchun - Carpathian Elder'],
                ],
            ], 200),
        ]);
        $this->actingAs(User::factory()->create());

        $project = ContentProject::factory()->create(['settings' => []]);

        Livewire::test(EditContentProject::class, ['record' => $project->getRouteKey()])
            ->assertSee('Did Vishchun - Carpathian Elder');
    }

    public function test_editing_target_platforms_and_default_voice_persists_them(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/voices' => Http::response([
                'voices' => [
                    ['voice_id' => 'JBFqnCBsd6RMkjVDRZzb', 'name' => 'George — Warm, Captivating Storyteller'],
                ],
            ], 200),
        ]);
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
