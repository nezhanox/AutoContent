<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\AssetSearchOptions;
use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;

final class LocalAssetProvider implements AssetProviderInterface
{
    /**
     * @return array<int, MediaAsset>
     */
    public function search(string $query, AssetSearchOptions $options): array
    {
        $queryWords = $this->words($query);

        return MediaAsset::query()
            ->whereIn('type', array_map(fn (MediaAssetType $type): string => $type->value, $options->types))
            ->when(
                $options->excludeAssetIds !== [],
                fn ($builder) => $builder->whereNotIn('id', $options->excludeAssetIds)
            )
            ->orderBy('id')
            ->get()
            ->map(fn (MediaAsset $asset): array => [$asset, $this->score($queryWords, $asset)])
            ->filter(fn (array $pair): bool => $pair[1] > 0)
            ->sortByDesc(fn (array $pair): int => $pair[1])
            ->take($options->maxResults)
            ->pluck(0)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function words(string $text): array
    {
        return array_values(array_filter(array_unique(array_map(
            static fn (string $word): string => mb_strtolower($word),
            preg_split('/\s+/u', trim($text)) ?: []
        ))));
    }

    /**
     * @param  array<int, string>  $queryWords
     */
    private function score(array $queryWords, MediaAsset $asset): int
    {
        $tags = array_map(
            static fn ($tag): string => mb_strtolower((string) $tag),
            $asset->metadata['tags'] ?? []
        );

        return count(array_intersect($queryWords, $tags));
    }
}
