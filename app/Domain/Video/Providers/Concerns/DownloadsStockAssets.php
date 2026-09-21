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
    ): ?MediaAsset {
        $path = "assets/stock/{$provider}/{$externalId}.{$extension}";

        $existing = MediaAsset::query()->where('path', $path)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $response = Http::timeout(30)->get($downloadUrl);
        } catch (Throwable $exception) {
            Log::warning('stock asset download failed', [
                'provider' => $provider,
                'url' => $downloadUrl,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('stock asset download failed', [
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
                'metadata' => ['source' => $provider, 'query' => $query, 'external_id' => $externalId],
                'hash' => hash('sha256', $bytes),
            ],
        );
    }
}
