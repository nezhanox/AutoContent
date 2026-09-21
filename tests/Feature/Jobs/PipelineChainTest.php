<?php

namespace Tests\Feature\Jobs;

use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\AudioProbeInterface;
use App\Domain\Video\Providers\FakeAssetProvider;
use App\Domain\Video\Providers\FakeAudioProbe;
use App\Domain\Video\Providers\FakeTranscriptionProvider;
use App\Domain\Video\Providers\FakeTtsProvider;
use App\Domain\Video\Providers\FakeVideoQualityChecker;
use App\Domain\Video\Providers\FakeVideoRenderer;
use App\Domain\Video\QualityCheckResult;
use App\Domain\Video\RenderResult;
use App\Domain\Video\TranscriptionProviderInterface;
use App\Domain\Video\TtsProviderInterface;
use App\Domain\Video\VideoQualityCheckerInterface;
use App\Domain\Video\VideoRendererInterface;
use App\Jobs\CollectVideoAssetsJob;
use App\Jobs\GenerateScenesJob;
use App\Jobs\GenerateScriptJob;
use App\Jobs\GenerateSubtitlesJob;
use App\Jobs\GenerateVoiceoverJob;
use App\Jobs\QualityCheckVideoJob;
use App\Jobs\RenderVideoJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoStatus;
use App\Models\MediaAsset;
use App\Models\Script;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PipelineChainTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_run_of_every_stage_dispatches_the_next_stage(): void
    {
        Storage::fake(config('filesystems.default'));

        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $project = ContentProject::factory()->create(['settings' => ['tts' => ['voice' => 'adam']]]);
        $idea = ContentIdea::factory()->create([
            'content_project_id' => $project->id,
            'status' => ContentIdeaStatus::Approved,
        ]);

        // Stage 1: script
        //
        // LlmManagerInterface is bound as a singleton (see LlmServiceProvider)
        // and caches the resolved provider instance per provider name once it
        // has been made from the container the first time. Re-binding
        // FakeLlmProvider::class in the container between stages would have
        // no effect after that first resolution, so a single shared instance
        // is bound once and its response is swapped via respondWith() before
        // each LLM-driven stage instead.
        $fakeLlm = new FakeLlmProvider;
        $this->app->instance(FakeLlmProvider::class, $fakeLlm);

        $fakeLlm->respondWith(json_encode([
            'title' => 'T', 'hook' => 'H', 'script' => 'Body', 'estimated_duration' => 6, 'cta' => 'Follow',
        ]));

        Queue::fake();
        app()->call([new GenerateScriptJob($idea->id), 'handle']);
        Queue::assertPushed(GenerateScenesJob::class);

        $script = Script::where('content_idea_id', $idea->id)->sole();

        // Stage 2: scenes
        $fakeLlm->respondWith(json_encode([
            'scenes' => [
                ['type' => 'hook', 'duration' => 3, 'visual_query' => 'a', 'text' => 'Hi'],
                ['type' => 'cta', 'duration' => 3, 'visual_query' => 'b', 'text' => 'Bye'],
            ],
        ]));

        Queue::fake();
        app()->call([new GenerateScenesJob($script->id), 'handle']);
        Queue::assertPushed(GenerateVoiceoverJob::class);

        $video = Video::where('script_id', $script->id)->sole();

        // Stage 3: voiceover
        $this->app->bind(TtsProviderInterface::class, function () {
            return (new FakeTtsProvider)->respondWith('audio-bytes', 'elevenlabs');
        });
        $this->app->bind(AudioProbeInterface::class, function () {
            return (new FakeAudioProbe)->respondWith(6.0);
        });

        Queue::fake();
        app()->call([new GenerateVoiceoverJob($video->id), 'handle']);
        Queue::assertPushed(CollectVideoAssetsJob::class);

        // Stage 4: assets
        $asset = MediaAsset::factory()->create(['type' => MediaAssetType::Video]);
        $this->app->bind(AssetProviderInterface::class, fn () => (new FakeAssetProvider)->respondWith([$asset]));

        Queue::fake();
        app()->call([new CollectVideoAssetsJob($video->id), 'handle']);
        Queue::assertPushed(GenerateSubtitlesJob::class);

        // Stage 5: subtitles
        $this->app->bind(TranscriptionProviderInterface::class, function () {
            return (new FakeTranscriptionProvider)->respondWith(
                [['start' => 0.0, 'end' => 6.0, 'text' => 'Hi Bye']],
                'en',
            );
        });

        Queue::fake();
        app()->call([new GenerateSubtitlesJob($video->id), 'handle']);
        Queue::assertPushed(RenderVideoJob::class);

        // Stage 6: render
        $this->app->bind(VideoRendererInterface::class, function () {
            return (new FakeVideoRenderer)->respondWith(
                new RenderResult(path: 'projects/1/renders/1.mp4', duration: 6.0, width: 1080, height: 1920)
            );
        });

        Queue::fake();
        app()->call([new RenderVideoJob($video->id), 'handle']);
        Queue::assertPushed(QualityCheckVideoJob::class);

        // Stage 7: quality check — end of chain
        $this->app->bind(VideoQualityCheckerInterface::class, function () {
            return (new FakeVideoQualityChecker)->respondWith(
                new QualityCheckResult(passed: true, checks: ['has_video_stream' => true], notes: [])
            );
        });

        Queue::fake();
        app()->call([new QualityCheckVideoJob($video->id), 'handle']);
        Queue::assertNothingPushed();

        $fresh = $video->fresh();
        $this->assertSame(VideoStatus::Rendered, $fresh->status);
        $this->assertTrue($fresh->quality_passed);
        $this->assertNull($fresh->failed_stage);
    }
}
