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

final class WikimediaAssetProvider implements AssetProviderInterface
{
    use DownloadsStockAssets;

    private const SUPPORTED_MIME_TYPES = ['image/jpeg', 'image/png'];

    /**
     * @return array<int, MediaAsset>
     */
    public function search(string $query, AssetSearchOptions $options): array
    {
        $typeValues = array_map(fn (MediaAssetType $type): string => $type->value, $options->types);

        if (array_intersect($typeValues, [MediaAssetType::Image->value, MediaAssetType::Thumbnail->value]) === []) {
            return [];
        }

        $hits = $this->searchPhotos($query, $options->maxResults);

        if ($hits === []) {
            return [];
        }

        $assets = [];

        foreach ($hits as $hit) {
            $asset = $this->ingest(
                provider: 'wikimedia',
                externalId: (string) $hit['id'],
                downloadUrl: $hit['url'],
                extension: $hit['extension'],
                type: MediaAssetType::Image,
                width: $hit['width'],
                height: $hit['height'],
                duration: null,
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
        $response = $this->request([
            'action' => 'query',
            'format' => 'json',
            'generator' => 'search',
            'gsrsearch' => $query,
            'gsrnamespace' => 6,
            'gsrlimit' => max(3, $maxResults),
            'prop' => 'imageinfo',
            'iiprop' => 'url|mime|size',
            'iiurlwidth' => 1600,
        ]);

        if ($response === null) {
            return [];
        }

        $pages = $response['query']['pages'] ?? [];

        return collect($pages)
            ->map(function (array $page): ?array {
                $info = $page['imageinfo'][0] ?? null;

                if ($info === null || ! in_array($info['mime'] ?? null, self::SUPPORTED_MIME_TYPES, true)) {
                    return null;
                }

                $url = $info['thumburl'] ?? $info['url'] ?? null;

                if (blank($url)) {
                    return null;
                }

                return [
                    'id' => $page['pageid'],
                    'url' => $url,
                    'extension' => $info['mime'] === 'image/png' ? 'png' : 'jpg',
                    'width' => $info['thumbwidth'] ?? $info['width'] ?? 0,
                    'height' => $info['thumbheight'] ?? $info['height'] ?? 0,
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
    private function request(array $query): ?array
    {
        try {
            $response = Http::baseUrl(config('assets.providers.wikimedia.base_url'))
                ->timeout(15)
                ->get('/w/api.php', $query);
        } catch (Throwable $exception) {
            Log::channel('video')->warning('wikimedia search request failed', ['error' => $exception->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::channel('video')->warning('wikimedia search request failed', ['status' => $response->status()]);

            return null;
        }

        return $response->json();
    }
}
