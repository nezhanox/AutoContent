<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\AssetSearchOptions;
use App\Models\MediaAsset;

final class FakeAssetProvider implements AssetProviderInterface
{
    /** @var array<int, MediaAsset> */
    private array $pool = [];

    /**
     * @param  array<int, MediaAsset>  $pool
     */
    public function respondWith(array $pool): static
    {
        $this->pool = $pool;

        return $this;
    }

    /**
     * @return array<int, MediaAsset>
     */
    public function search(string $query, AssetSearchOptions $options): array
    {
        return array_values(array_filter(
            $this->pool,
            fn (MediaAsset $asset): bool => in_array($asset->type, $options->types, true)
                && ! in_array($asset->id, $options->excludeAssetIds, true)
        ));
    }
}
