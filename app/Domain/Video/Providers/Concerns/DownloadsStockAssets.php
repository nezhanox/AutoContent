<?php

namespace App\Domain\Video\Providers\Concerns;

use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

trait DownloadsStockAssets
{
    // Style/medium words that GenerateScenesService's own prompt tells the LLM to
    // append after a subject (e.g. "Epictetus statue", "Seneca portrait painting") —
    // stripped before relevance-checking a query against a hit's real tags/alt text,
    // since these words appear in almost every stock photo's own metadata regardless
    // of subject and would otherwise make every hit look "relevant" no matter what.
    private const GENERIC_MEDIUM_WORDS = [
        'statue', 'bust', 'portrait', 'painting', 'photo', 'photograph',
        'picture', 'image', 'abstract', 'close', 'closeup', 'up',
        'background', 'art', 'sculpture', 'drawing',
    ];

    protected function ingest(
        string $provider,
        string $externalId,
        string $downloadUrl,
        string $extension,
        MediaAssetType $type,
        int $width,
        int $height,
        ?int $duration,
        string $query,
        string $description = '',
    ): ?MediaAsset {
        $path = "assets/stock/{$provider}/{$externalId}.{$extension}";

        $existing = MediaAsset::query()->where('path', $path)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $response = Http::timeout(30)->get($downloadUrl);
        } catch (Throwable $exception) {
            Log::channel('video')->warning('stock asset download failed', [
                'provider' => $provider,
                'url' => $downloadUrl,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::channel('video')->warning('stock asset download failed', [
                'provider' => $provider,
                'url' => $downloadUrl,
                'status' => $response->status(),
            ]);

            return null;
        }

        $bytes = $response->body();
        Storage::disk(config('filesystems.default'))->put($path, $bytes);

        return MediaAsset::query()->firstOrCreate(
            ['path' => $path],
            [
                'type' => $type,
                'provider' => $provider,
                'mime_type' => $type === MediaAssetType::Video ? 'video/mp4' : 'image/jpeg',
                'width' => $width,
                'height' => $height,
                'duration' => $duration,
                'metadata' => ['source' => $provider, 'query' => $query, 'external_id' => $externalId, 'tags' => $this->tagsFor($description !== '' ? $description : $query)],
                'hash' => hash('sha256', $bytes),
            ],
        );
    }

    /**
     * Rejects a candidate hit only when we have real description data from the
     * provider AND it shares no significant word with the query — e.g. Pixabay's
     * own tags for its top hit on "Epictetus statue" were "buddha, statue, moss,
     * zen, japan, buddhism" (the real bug this catches). A blank description
     * (provider sent nothing to check, or the query was only generic medium
     * words with nothing left to check against) is accepted by default: there
     * is no evidence to reject on.
     */
    protected function isRelevant(string $query, string $description): bool
    {
        if (trim($description) === '') {
            return true;
        }

        $queryWords = array_diff($this->tagsFor($query), self::GENERIC_MEDIUM_WORDS);

        if ($queryWords === []) {
            return true;
        }

        return array_intersect($queryWords, $this->tagsFor($description)) !== [];
    }

    /**
     * @return array<int, string>
     */
    private function tagsFor(string $text): array
    {
        return array_values(array_filter(array_unique(array_map(
            static fn (string $word): string => mb_strtolower($word),
            preg_split('/[^\p{L}\p{N}]+/u', trim($text)) ?: []
        ))));
    }
}
