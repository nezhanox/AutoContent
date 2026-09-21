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

final class PixabayAssetProvider implements AssetProviderInterface
{
    use DownloadsStockAssets;

    /**
     * @return array<int, MediaAsset>
     */
    public function search(string $query, AssetSearchOptions $options): array
    {
        $wantsVideo = array_intersect(
            array_map(fn (MediaAssetType $type) => $type->value, $options->types),
            [MediaAssetType::Video->value, MediaAssetType::ScreenRecording->value]
        ) !== [];
        $wantsImage = array_intersect(
            array_map(fn (MediaAssetType $type) => $type->value, $options->types),
            [MediaAssetType::Image->value, MediaAssetType::Thumbnail->value]
        ) !== [];

        if (! $wantsVideo && ! $wantsImage) {
            return [];
        }

        $hits = [];
        $hitType = null;

        if ($wantsVideo) {
            $hits = $this->searchVideos($query, $options->maxResults);
            $hitType = MediaAssetType::Video;
        }

        if ($hits === [] && $wantsImage) {
            $hits = $this->searchPhotos($query, $options->maxResults);
            $hitType = MediaAssetType::Image;
        }

        if ($hits === []) {
            return [];
        }

        $assets = [];

        foreach ($hits as $hit) {
            $asset = $this->ingest(
                provider: 'pixabay',
                externalId: (string) $hit['id'],
                downloadUrl: $hit['url'],
                extension: $hit['extension'],
                type: $hitType,
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
        $response = $this->request('/', [
            'q' => $query,
            'image_type' => 'photo',
            'orientation' => 'vertical',
            'safesearch' => 'true',
            'per_page' => max(3, $maxResults),
        ]);

        if ($response === null) {
            return [];
        }

        return collect($response['hits'] ?? [])
            ->map(function (array $hit): ?array {
                if (blank($hit['largeImageURL'] ?? null)) {
                    return null;
                }

                return [
                    'id' => $hit['id'],
                    'url' => $hit['largeImageURL'],
                    'extension' => 'jpg',
                    'width' => $hit['imageWidth'] ?? 0,
                    'height' => $hit['imageHeight'] ?? 0,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, url: string, extension: string, width: int, height: int, duration: ?int}>
     */
    private function searchVideos(string $query, int $maxResults): array
    {
        $response = $this->request('/videos/', [
            'q' => $query,
            'safesearch' => 'true',
            'per_page' => max(3, $maxResults),
        ]);

        if ($response === null) {
            return [];
        }

        return collect($response['hits'] ?? [])
            ->map(function (array $hit): ?array {
                $file = $hit['videos']['large'] ?? $hit['videos']['medium'] ?? null;

                if ($file === null || blank($file['url'] ?? null)) {
                    return null;
                }

                $width = $file['width'] ?? 0;
                $height = $file['height'] ?? 0;

                // Pixabay's video search has no `orientation` parameter (unlike its
                // photo search and unlike Pexels), so we filter out landscape hits
                // client-side to avoid letterboxing them into a tiny strip on the
                // 1080x1920 portrait canvas.
                if ($width >= $height) {
                    return null;
                }

                return [
                    'id' => $hit['id'],
                    'url' => $file['url'],
                    'extension' => 'mp4',
                    'width' => $width,
                    'height' => $height,
                    'duration' => isset($hit['duration']) ? (int) $hit['duration'] : null,
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
        $apiKey = config('assets.providers.pixabay.api_key');

        if (blank($apiKey)) {
            return null;
        }

        try {
            $response = Http::baseUrl(config('assets.providers.pixabay.base_url'))
                ->timeout(15)
                ->get($path, [...$query, 'key' => $apiKey]);
        } catch (Throwable $exception) {
            Log::channel('video')->warning('pixabay search request failed', ['error' => $exception->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::channel('video')->warning('pixabay search request failed', ['status' => $response->status()]);

            return null;
        }

        return $response->json();
    }
}
