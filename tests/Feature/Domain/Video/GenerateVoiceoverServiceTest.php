<?php

namespace Tests\Feature\Domain\Video;

use App\Domain\Video\Providers\FakeAudioProbe;
use App\Domain\Video\Providers\FakeTtsProvider;
use App\Domain\Video\Services\GenerateVoiceoverService;
use App\Models\ContentProject;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class GenerateVoiceoverServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_concatenates_scene_text_in_order_and_uses_the_project_voice(): void
    {
        $project = ContentProject::factory()->create(['settings' => ['tts' => ['voice' => 'rachel']]]);
        $video = Video::factory()->create(['content_project_id' => $project->id]);

        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 1, 'text' => 'second']);
        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 0, 'text' => 'first']);

        $tts = (new FakeTtsProvider)->respondWith('audio-bytes', 'fake', ['duration_hint' => 5]);
        $audioProbe = (new FakeAudioProbe)->respondWith(7.5);
        $service = new GenerateVoiceoverService($tts, $audioProbe);

        $result = $service->generate($video->fresh(['scenes'])->load('contentProject'));

        $this->assertSame('first second', $result['text']);
        $this->assertSame('audio-bytes', $result['audio']);
        $this->assertSame('fake', $result['provider']);
        $this->assertSame('rachel', $result['voice']);
        $this->assertSame(7.5, $result['duration']);
        $this->assertSame(['duration_hint' => 5], $result['metadata']);
    }

    public function test_it_falls_back_to_the_config_default_voice_when_the_project_has_none(): void
    {
        config()->set('tts.default_voice', 'adam');

        $project = ContentProject::factory()->create(['settings' => []]);
        $video = Video::factory()->create(['content_project_id' => $project->id]);
        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 0, 'text' => 'hi']);

        $tts = (new FakeTtsProvider)->respondWith('audio-bytes');
        $service = new GenerateVoiceoverService($tts, new FakeAudioProbe);

        $result = $service->generate($video->fresh(['scenes'])->load('contentProject'));

        $this->assertSame('adam', $result['voice']);
    }

    public function test_it_prefers_the_project_voice_over_the_config_default(): void
    {
        config()->set('tts.default_voice', 'adam');

        $project = ContentProject::factory()->create(['settings' => ['tts' => ['voice' => 'bella']]]);
        $video = Video::factory()->create(['content_project_id' => $project->id]);
        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 0, 'text' => 'hi']);

        $tts = (new FakeTtsProvider)->respondWith('audio-bytes');
        $service = new GenerateVoiceoverService($tts, new FakeAudioProbe);

        $result = $service->generate($video->fresh(['scenes'])->load('contentProject'));

        $this->assertSame('bella', $result['voice']);
    }

    public function test_it_throws_when_no_voice_is_configured_anywhere(): void
    {
        config()->set('tts.default_voice', null);

        $project = ContentProject::factory()->create(['settings' => []]);
        $video = Video::factory()->create(['content_project_id' => $project->id]);
        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 0, 'text' => 'hi']);

        $service = new GenerateVoiceoverService(new FakeTtsProvider, new FakeAudioProbe);

        $this->expectException(InvalidArgumentException::class);

        $service->generate($video->fresh(['scenes'])->load('contentProject'));
    }
}
