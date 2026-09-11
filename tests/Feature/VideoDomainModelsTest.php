<?php

namespace Tests\Feature;

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
}
