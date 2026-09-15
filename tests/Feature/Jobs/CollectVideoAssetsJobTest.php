<?php

namespace Tests\Feature\Jobs;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\Exceptions\AssetNotFoundException;
use App\Domain\Video\Providers\FakeAssetProvider;
use App\Jobs\CollectVideoAssetsJob;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoSceneType;
use App\Models\Enums\VideoStatus;
use App\Models\MediaAsset;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectVideoAssetsJobTest extends TestCase
{
    use RefreshDatabase;

    private function bindFakeAssets(array $pool): void
    {
        $this->app->bind(AssetProviderInterface::class, fn () => (new FakeAssetProvider)->respondWith($pool));
    }

    public function test_it_assigns_assets_and_marks_the_video_assets_ready(): void
    {
        $video = Video::factory()->create(['status' => VideoStatus::VoiceGenerated]);
        $scene = VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 0, 'type' => VideoSceneType::Broll,
            'visual_query' => 'ai server', 'asset_id' => null,
        ]);

        $match = MediaAsset::factory()->create(['type' => MediaAssetType::Video]);
        $this->bindFakeAssets([$match]);

        app()->call([new CollectVideoAssetsJob($video->id), 'handle']);

        $this->assertSame($match->id, $scene->fresh()->asset_id);
        $this->assertSame(VideoStatus::AssetsReady, $video->fresh()->status);
    }

    public function test_it_is_a_no_op_when_the_video_status_is_not_voice_generated(): void
    {
        $video = Video::factory()->create(['status' => VideoStatus::ScriptGenerated]);
        VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 0, 'type' => VideoSceneType::Broll,
            'visual_query' => 'ai server', 'asset_id' => null,
        ]);
        $this->bindFakeAssets([MediaAsset::factory()->create(['type' => MediaAssetType::Video])]);

        app()->call([new CollectVideoAssetsJob($video->id), 'handle']);

        $this->assertSame(VideoStatus::ScriptGenerated, $video->fresh()->status);
    }

    public function test_it_only_fills_scenes_still_missing_an_asset_on_a_repeat_run(): void
    {
        $video = Video::factory()->create(['status' => VideoStatus::VoiceGenerated]);
        $preAssigned = MediaAsset::factory()->create(['type' => MediaAssetType::Video]);
        $sceneAlreadyDone = VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 0, 'type' => VideoSceneType::Broll,
            'visual_query' => 'a', 'asset_id' => $preAssigned->id,
        ]);
        $sceneNeedsOne = VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 1, 'type' => VideoSceneType::Broll,
            'visual_query' => 'b', 'asset_id' => null,
        ]);

        $match = MediaAsset::factory()->create(['type' => MediaAssetType::Video]);
        $this->bindFakeAssets([$match]);

        app()->call([new CollectVideoAssetsJob($video->id), 'handle']);

        $this->assertSame($preAssigned->id, $sceneAlreadyDone->fresh()->asset_id);
        $this->assertSame($match->id, $sceneNeedsOne->fresh()->asset_id);
        $this->assertSame(VideoStatus::AssetsReady, $video->fresh()->status);
    }

    public function test_a_video_with_no_scenes_needing_assets_still_becomes_assets_ready(): void
    {
        $video = Video::factory()->create(['status' => VideoStatus::VoiceGenerated]);
        VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 0, 'type' => VideoSceneType::Text,
            'visual_query' => null, 'asset_id' => null,
        ]);
        $this->bindFakeAssets([]);

        app()->call([new CollectVideoAssetsJob($video->id), 'handle']);

        $this->assertSame(VideoStatus::AssetsReady, $video->fresh()->status);
    }

    public function test_it_throws_and_leaves_the_video_unchanged_when_no_asset_matches(): void
    {
        $video = Video::factory()->create(['status' => VideoStatus::VoiceGenerated]);
        VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 0, 'type' => VideoSceneType::Broll,
            'visual_query' => 'anything', 'asset_id' => null,
        ]);
        $this->bindFakeAssets([]);

        $this->expectException(AssetNotFoundException::class);

        app()->call([new CollectVideoAssetsJob($video->id), 'handle']);

        $this->assertSame(VideoStatus::VoiceGenerated, $video->fresh()->status);
    }
}
