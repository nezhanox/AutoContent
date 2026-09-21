<?php

namespace App\Domain\Video\Services;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\AssetSearchOptions;
use App\Domain\Video\Exceptions\AssetNotFoundException;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoSceneType;
use App\Models\Video;

final class CollectVideoAssetsService
{
    public function __construct(private readonly AssetProviderInterface $assetProvider) {}

    /**
     * @return array<int, int> scene_id => media_asset_id
     */
    public function collect(Video $video): array
    {
        $assignments = [];
        $usedAssetIds = [];

        foreach ($video->scenes as $scene) {
            // asset_id !== null is defensive against manual/admin edits (e.g. someone
            // hand-assigns a scene's asset via the VideoSceneResource edit form before
            // this job runs) — it is not partial-failure recovery, since collect() is
            // all-or-nothing (it throws before returning anything) and the job persists
            // atomically in one transaction, so a genuinely partial prior run cannot exist.
            if (blank($scene->visual_query) || $scene->asset_id !== null) {
                continue;
            }

            $types = $this->typesFor($scene->type);

            $results = $this->assetProvider->search(
                $scene->visual_query,
                new AssetSearchOptions(types: $types, maxResults: 1, excludeAssetIds: $usedAssetIds)
            );

            if ($results === [] && $usedAssetIds !== []) {
                $results = $this->assetProvider->search(
                    $scene->visual_query,
                    new AssetSearchOptions(types: $types, maxResults: 1)
                );
            }

            if ($results === []) {
                throw new AssetNotFoundException(
                    "No local asset found for scene #{$scene->id} (visual_query: \"{$scene->visual_query}\")."
                );
            }

            $assignments[$scene->id] = $results[0]->id;
            $usedAssetIds[] = $results[0]->id;
        }

        return $assignments;
    }

    /**
     * @return array<int, MediaAssetType>
     */
    private function typesFor(VideoSceneType $type): array
    {
        return match ($type) {
            VideoSceneType::ScreenRecording => [MediaAssetType::ScreenRecording],
            VideoSceneType::Screenshot, VideoSceneType::Image => [MediaAssetType::Image],
            default => [MediaAssetType::Video, MediaAssetType::Image],
        };
    }
}
