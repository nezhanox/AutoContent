<?php

namespace App\Domain\Video;

use App\Models\MediaAsset;

interface AssetProviderInterface
{
    /**
     * @return array<int, MediaAsset>
     */
    public function search(string $query, AssetSearchOptions $options): array;
}
