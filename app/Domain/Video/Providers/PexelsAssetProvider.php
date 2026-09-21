<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\AssetSearchOptions;
use App\Domain\Video\Providers\Concerns\DownloadsStockAssets;
use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PexelsAssetProvider implements AssetProviderInterface
{
    use DownloadsStockAssets;

    /**
     * @return array<int, MediaAsset>
     */
    public function search(string $query, AssetSearchOptions $options): array
    {
        $typeValues = array_map(fn (MediaAssetType $type): string => $type->value, $options->types);
        $wantsVideo = array_intersect($typeValues, [MediaAssetType::Video->value, MediaAssetType::ScreenRecording->value]) !== [];
        $wantsImage = array_intersect($typeValues, [MediaAssetType::Image->value, MediaAssetType::Thumbnail->value]) !== [];

        if (! $wantsVideo && ! $wantsImage) {
            return [];
        }

        $hits = $wantsVideo
            ? $this->searchVideos($query, $options->maxResults)
            : $this->searchPhotos($query, $options->maxResults);

        $assets = [];

        foreach ($hits as $hit) {
            $asset = $this->ingest(
                provider: 'pexels',
                externalId: (string) $hit['id'],
                downloadUrl: $hit['url'],
                extension: $hit['extension'],
                type: $wantsVideo ? MediaAssetType::Video : MediaAssetType::Image,
                width: $hit['width'],
                height: $hit['height'],
                duration: $hit['duration'] ?? null,
                query: $query,
            );

            if ($asset === null || in_array($asset->id, $options->excludeAssetIds, true)) {
                continue;
            }

            $assets[] = $asset;

            if (count($assets) >= $options->maxResults) {
                break;
            }
        }

        return $assets;
    }

    /**
     * @return array<int, array{id: int, url: string, extension: string, width: int, height: int}>
     */
    private function searchPhotos(string $query, int $maxResults): array
    {
        $response = $this->request('/v1/search', [
            'query' => $query,
            'orientation' => 'portrait',
            'per_page' => max(3, $maxResults),
        ]);

        if ($response === null) {
            return [];
        }

        return collect($response['photos'] ?? [])
            ->map(fn (array $photo): array => [
                'id' => $photo['id'],
                'url' => $photo['src']['original'],
                'extension' => 'jpg',
                'width' => $photo['width'] ?? 0,
                'height' => $photo['height'] ?? 0,
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: int, url: string, extension: string, width: int, height: int, duration: ?int}>
     */
    private function searchVideos(string $query, int $maxResults): array
    {
        $response = $this->request('/videos/search', [
            'query' => $query,
            'orientation' => 'portrait',
            'per_page' => max(3, $maxResults),
        ]);

        if ($response === null) {
            return [];
        }

        return collect($response['videos'] ?? [])
            ->map(function (array $video): ?array {
                $largest = collect($video['video_files'] ?? [])
                    ->filter(fn (array $file): bool => filled($file['link'] ?? null))
                    ->sortByDesc(fn (array $file): int => ($file['width'] ?? 0) * ($file['height'] ?? 0))
                    ->first();

                if ($largest === null) {
                    return null;
                }

                return [
                    'id' => $video['id'],
                    'url' => $largest['link'],
                    'extension' => 'mp4',
                    'width' => $largest['width'] ?? 0,
                    'height' => $largest['height'] ?? 0,
                    'duration' => isset($video['duration']) ? (int) $video['duration'] : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    private function request(string $path, array $query): ?array
    {
        try {
            $response = Http::baseUrl(config('assets.providers.pexels.base_url'))
                ->withHeaders(['Authorization' => config('assets.providers.pexels.api_key')])
                ->timeout(15)
                ->get($path, $query);
        } catch (Throwable $exception) {
            Log::warning('pexels search request failed', ['error' => $exception->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('pexels search request failed', ['status' => $response->status()]);

            return null;
        }

        return $response->json();
    }
}
