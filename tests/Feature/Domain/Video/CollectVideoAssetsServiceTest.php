<?php

namespace Tests\Feature\Domain\Video;

use App\Domain\Video\Exceptions\AssetNotFoundException;
use App\Domain\Video\Providers\FakeAssetProvider;
use App\Domain\Video\Services\CollectVideoAssetsService;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoSceneType;
use App\Models\MediaAsset;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectVideoAssetsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_assigns_an_asset_only_to_scenes_that_need_one(): void
    {
        $video = Video::factory()->create();

        $needsAsset = VideoScene::factory()->create([
            'video_id' => $video->id,
            'order' => 0,
            'type' => VideoSceneType::Broll,
            'visual_query' => 'ai server',
            'asset_id' => null,
        ]);
        VideoScene::factory()->create([
            'video_id' => $video->id,
            'order' => 1,
            'type' => VideoSceneType::Text,
            'visual_query' => null,
            'asset_id' => null,
        ]);
        $alreadyAssigned = MediaAsset::factory()->create(['type' => MediaAssetType::Video]);
        VideoScene::factory()->create([
            'video_id' => $video->id,
            'order' => 2,
            'type' => VideoSceneType::Broll,
            'visual_query' => 'server room',
            'asset_id' => $alreadyAssigned->id,
        ]);

        $match = MediaAsset::factory()->create(['type' => MediaAssetType::Video]);
        $provider = (new FakeAssetProvider)->respondWith([$match]);

        $service = new CollectVideoAssetsService($provider);
        $assignments = $service->collect($video->fresh(['scenes']));

        $this->assertSame([$needsAsset->id => $match->id], $assignments);
    }

    public function test_it_maps_screen_recording_scenes_to_screen_recording_assets_only(): void
    {
        $video = Video::factory()->create();
        $scene = VideoScene::factory()->create([
            'video_id' => $video->id,
            'order' => 0,
            'type' => VideoSceneType::ScreenRecording,
            'visual_query' => 'ide demo',
            'asset_id' => null,
        ]);

        $wrongType = MediaAsset::factory()->create(['type' => MediaAssetType::Video]);
        $rightType = MediaAsset::factory()->create(['type' => MediaAssetType::ScreenRecording]);
        $provider = (new FakeAssetProvider)->respondWith([$wrongType, $rightType]);

        $service = new CollectVideoAssetsService($provider);
        $assignments = $service->collect($video->fresh(['scenes']));

        $this->assertSame([$scene->id => $rightType->id], $assignments);
    }

    public function test_it_deduplicates_assets_across_scenes_but_falls_back_to_reuse_when_the_library_is_small(): void
    {
        $video = Video::factory()->create();

        $scene1 = VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 0, 'type' => VideoSceneType::Broll,
            'visual_query' => 'a', 'asset_id' => null,
        ]);
        $scene2 = VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 1, 'type' => VideoSceneType::Broll,
            'visual_query' => 'b', 'asset_id' => null,
        ]);
        $scene3 = VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 2, 'type' => VideoSceneType::Broll,
            'visual_query' => 'c', 'asset_id' => null,
        ]);

        $assetA = MediaAsset::factory()->create(['type' => MediaAssetType::Video]);
        $assetB = MediaAsset::factory()->create(['type' => MediaAssetType::Video]);
        $provider = (new FakeAssetProvider)->respondWith([$assetA, $assetB]);

        $service = new CollectVideoAssetsService($provider);
        $assignments = $service->collect($video->fresh(['scenes']));

        $this->assertSame([
            $scene1->id => $assetA->id,
            $scene2->id => $assetB->id,
            $scene3->id => $assetA->id,
        ], $assignments);
    }

    public function test_it_skips_scenes_with_an_empty_string_visual_query(): void
    {
        $video = Video::factory()->create();

        VideoScene::factory()->create([
            'video_id' => $video->id,
            'order' => 0,
            'type' => VideoSceneType::Text,
            'visual_query' => '',
            'asset_id' => null,
        ]);

        $service = new CollectVideoAssetsService(new FakeAssetProvider);
        $assignments = $service->collect($video->fresh(['scenes']));

        $this->assertSame([], $assignments);
    }

    public function test_it_throws_when_no_asset_matches_and_nothing_was_used_yet(): void
    {
        $video = Video::factory()->create();
        VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 0, 'type' => VideoSceneType::Broll,
            'visual_query' => 'anything', 'asset_id' => null,
        ]);

        $service = new CollectVideoAssetsService(new FakeAssetProvider);

        $this->expectException(AssetNotFoundException::class);

        $service->collect($video->fresh(['scenes']));
    }
}
