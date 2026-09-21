<?php

namespace Tests\Feature;

use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoStatus;
use App\Models\MediaAsset;
use App\Models\Video;
use App\Models\VideoScene;
use App\Models\Voiceover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoDomainModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_belongs_to_project_idea_and_script(): void
    {
        $video = Video::factory()->create();

        $this->assertNotNull($video->contentProject);
        $this->assertNotNull($video->contentIdea);
        $this->assertNotNull($video->script);
        $this->assertSame(VideoStatus::Draft, $video->status);
    }

    public function test_video_has_many_ordered_scenes(): void
    {
        $video = Video::factory()->create();
        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 2]);
        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 1]);

        $this->assertSame([1, 2], $video->scenes->pluck('order')->all());
    }

    public function test_video_has_one_voiceover(): void
    {
        $video = Video::factory()->create();
        $voiceover = Voiceover::factory()->create(['video_id' => $video->id]);

        $this->assertTrue($video->voiceover->is($voiceover));
    }

    public function test_video_scene_can_reference_media_asset_and_survives_asset_deletion(): void
    {
        $asset = MediaAsset::factory()->create();
        $scene = VideoScene::factory()->create(['asset_id' => $asset->id]);

        $asset->delete();

        $this->assertDatabaseHas('video_scenes', ['id' => $scene->id, 'asset_id' => null]);
    }

    public function test_deleting_video_cascades_to_scenes_and_voiceover(): void
    {
        $video = Video::factory()->create();
        $scene = VideoScene::factory()->create(['video_id' => $video->id]);
        $voiceover = Voiceover::factory()->create(['video_id' => $video->id]);

        $video->delete();

        $this->assertDatabaseMissing('video_scenes', ['id' => $scene->id]);
        $this->assertDatabaseMissing('voiceovers', ['id' => $voiceover->id]);
    }

    public function test_a_video_can_have_a_music_asset(): void
    {
        $music = MediaAsset::factory()->create(['type' => MediaAssetType::Audio]);
        $video = Video::factory()->create(['music_asset_id' => $music->id]);

        $this->assertTrue($video->musicAsset->is($music));
    }

    public function test_music_asset_id_is_nullable(): void
    {
        $video = Video::factory()->create(['music_asset_id' => null]);

        $this->assertNull($video->fresh()->musicAsset);
    }

    public function test_quality_passed_and_quality_report_are_cast(): void
    {
        $video = Video::factory()->create([
            'quality_passed' => true,
            'quality_report' => ['checks' => ['has_video_stream' => true]],
        ]);

        $fresh = $video->fresh();

        $this->assertTrue($fresh->quality_passed);
        $this->assertSame(['checks' => ['has_video_stream' => true]], $fresh->quality_report);
    }

    public function test_current_stage_label_reflects_progress_through_the_pipeline(): void
    {
        $video = Video::factory()->create(['status' => VideoStatus::ScriptGenerated]);
        $this->assertSame('Generating voiceover', $video->currentStageLabel());

        $video->update(['status' => VideoStatus::VoiceGenerated]);
        $this->assertSame('Collecting assets', $video->fresh()->currentStageLabel());

        $video->update(['status' => VideoStatus::AssetsReady, 'subtitle_id' => null]);
        $this->assertSame('Generating subtitles', $video->fresh()->currentStageLabel());

        $subtitle = MediaAsset::factory()->create(['type' => MediaAssetType::Subtitle]);
        $video->update(['subtitle_id' => $subtitle->id]);
        $this->assertSame('Rendering', $video->fresh()->currentStageLabel());

        $video->update(['status' => VideoStatus::Rendered, 'quality_report' => null]);
        $this->assertSame('Checking quality', $video->fresh()->currentStageLabel());

        $video->update(['quality_report' => ['checks' => []], 'quality_passed' => true]);
        $this->assertSame('Done', $video->fresh()->currentStageLabel());
    }

    public function test_current_stage_label_reports_a_failed_quality_check_instead_of_done(): void
    {
        $video = Video::factory()->create([
            'status' => VideoStatus::Rendered,
            'quality_passed' => false,
            'quality_report' => ['checks' => ['has_audio_stream' => false]],
        ]);

        $this->assertSame('Quality check failed', $video->currentStageLabel());

        $video->update(['quality_passed' => true]);
        $this->assertSame('Done', $video->fresh()->currentStageLabel());
    }

    public function test_current_stage_label_explains_a_video_stuck_in_rendering(): void
    {
        $video = Video::factory()->create([
            'status' => VideoStatus::Rendering,
            'failed_stage' => null,
        ]);

        $this->assertSame('Rendering (retry if stalled)', $video->currentStageLabel());
    }

    public function test_current_stage_label_on_failure_names_the_failed_stage(): void
    {
        $video = Video::factory()->create([
            'status' => VideoStatus::Failed,
            'failed_stage' => 'voiceover',
        ]);

        $this->assertSame('Failed: Voiceover', $video->currentStageLabel());
    }
}
