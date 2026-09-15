<?php

namespace App\Domain\Video;

use App\Models\Enums\MediaAssetType;

final class AssetSearchOptions
{
    /**
     * @param  array<int, MediaAssetType>  $types
     * @param  array<int, int>  $excludeAssetIds
     */
    public function __construct(
        public readonly array $types,
        public readonly int $maxResults = 5,
        public readonly array $excludeAssetIds = [],
    ) {}
}
