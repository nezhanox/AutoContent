<?php

namespace Tests\Feature\Jobs;

use App\Domain\Video\Providers\FakeVideoRenderer;
use App\Domain\Video\RenderResult;
use App\Domain\Video\VideoRendererInterface;
use App\Jobs\RenderVideoJob;
use App\Models\ContentProject;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoStatus;
use App\Models\MediaAsset;
use App\Models\Video;
use App\Models\VideoScene;
use App\Models\Voiceover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenderVideoJobTest extends TestCase
{
    use RefreshDatabase;

    private function bindFakeRenderer(?RenderResult $result = null): void
    {
        $this->app->bind(VideoRendererInterface::class, function () use ($result) {
            $fake = new FakeVideoRenderer;

            return $result !== null ? $fake->respondWith($result) : $fake;
        });
    }

    private function videoReadyForRendering(): Video
    {
        $project = ContentProject::factory()->create();
        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'status' => VideoStatus::AssetsReady,
        ]);

        $asset = MediaAsset::factory()->create(['type' => MediaAssetType::Image]);
        VideoScene::factory()->create(['video_id' => $video->id, 'asset_id' => $asset->id]);

        Voiceover::factory()->create(['video_id' => $video->id]);

        $subtitle = MediaAsset::factory()->create(['type' => MediaAssetType::Subtitle]);
        $video->update(['subtitle_id' => $subtitle->id]);

        return $video;
    }

    public function test_it_renders_the_video_and_updates_it_from_the_render_result(): void
    {
        $this->bindFakeRenderer(new RenderResult(path: 'projects/1/renders/1.mp4', duration: 12.5, width: 1080, height: 1920));

        $video = $this->videoReadyForRendering();

        app()->call([new RenderVideoJob($video->id), 'handle']);

        $fresh = $video->fresh();
        $this->assertSame(VideoStatus::Rendered, $fresh->status);
        $this->assertSame('projects/1/renders/1.mp4', $fresh->file_path);
        $this->assertSame(13, $fresh->duration);
        $this->assertSame(1080, $fresh->width);
        $this->assertSame(1920, $fresh->height);
    }

    public function test_it_is_a_no_op_when_the_video_status_is_not_assets_ready(): void
    {
        $this->bindFakeRenderer();

        $video = $this->videoReadyForRendering();
        $video->update(['status' => VideoStatus::VoiceGenerated]);

        app()->call([new RenderVideoJob($video->id), 'handle']);

        $this->assertSame(VideoStatus::VoiceGenerated, $video->fresh()->status);
        $this->assertNull($video->fresh()->file_path);
    }

    public function test_it_is_a_no_op_when_there_is_no_subtitle(): void
    {
        $this->bindFakeRenderer();

        $video = $this->videoReadyForRendering();
        $video->update(['subtitle_id' => null]);

        app()->call([new RenderVideoJob($video->id), 'handle']);

        $this->assertSame(VideoStatus::AssetsReady, $video->fresh()->status);
        $this->assertNull($video->fresh()->file_path);
    }

    public function test_it_is_a_no_op_when_a_scene_has_no_asset(): void
    {
        $this->bindFakeRenderer();

        $video = $this->videoReadyForRendering();
        VideoScene::factory()->create(['video_id' => $video->id, 'asset_id' => null]);

        app()->call([new RenderVideoJob($video->id), 'handle']);

        $this->assertSame(VideoStatus::AssetsReady, $video->fresh()->status);
        $this->assertNull($video->fresh()->file_path);
    }

    public function test_it_is_a_no_op_when_there_is_no_voiceover(): void
    {
        $this->bindFakeRenderer();

        $project = ContentProject::factory()->create();
        $video = Video::factory()->create(['content_project_id' => $project->id, 'status' => VideoStatus::AssetsReady]);
        $asset = MediaAsset::factory()->create(['type' => MediaAssetType::Image]);
        VideoScene::factory()->create(['video_id' => $video->id, 'asset_id' => $asset->id]);
        $subtitle = MediaAsset::factory()->create(['type' => MediaAssetType::Subtitle]);
        $video->update(['subtitle_id' => $subtitle->id]);

        app()->call([new RenderVideoJob($video->id), 'handle']);

        $this->assertSame(VideoStatus::AssetsReady, $video->fresh()->status);
        $this->assertNull($video->fresh()->file_path);
    }
}
