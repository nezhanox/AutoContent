# Phase 6 stock asset providers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let `CollectVideoAssetsJob` find visuals for any topic by searching Pixabay and Pexels stock media before falling back to the existing local library, so the dashboard's "Generate Video" action works end-to-end without manual intervention.

**Architecture:** Two new `AssetProviderInterface` implementations (`PixabayAssetProvider`, `PexelsAssetProvider`) call their respective stock-media REST APIs, download the best match, and register it as a normal `MediaAsset` row (deduplicated by storage path). A new `ChainedAssetProvider` (also implementing `AssetProviderInterface`) tries an ordered list of providers and returns the first non-empty result, swallowing any single provider's failure. `AssetServiceProvider` rebinds `AssetProviderInterface` to a chain built from config instead of the bare `LocalAssetProvider`. `CollectVideoAssetsService`, `CollectVideoAssetsJob`, and `FfmpegVideoRenderer` are untouched — the interface contract does not change.

**Tech Stack:** Laravel 12, PHP 8.4, `Illuminate\Support\Facades\Http` (Guzzle-backed HTTP client), Pest-style PHPUnit (`php artisan test`), Pixabay REST API, Pexels REST API.

**Spec:** `docs/superpowers/specs/2026-09-21-phase6-stock-asset-providers-design.md`

## Global Constraints

- `AssetProviderInterface`/`AssetSearchOptions` (from Phase 3c) do not change signature — every new class implements the existing contract.
- `CollectVideoAssetsService`, `CollectVideoAssetsJob`, `FfmpegVideoRenderer` are not modified by this plan.
- A single provider failing (bad key, timeout, rate limit, malformed response) must never throw out of `ChainedAssetProvider::search()` — always fall through to the next provider, log a warning, and return `[]` only if every provider in the chain is exhausted.
- Downloaded files live at `storage/app/private/assets/stock/{provider}/{external_id}.{ext}`; a `MediaAsset` with that exact `path` must never be downloaded twice — check for an existing row by `path` before making the download request.
- Orientation requested from both APIs is portrait/vertical (matches the 1080×1920 render target).
- All new HTTP calls are covered by `Http::fake()` in tests — no task in this plan requires a live `PIXABAY_API_KEY`/`PEXELS_API_KEY` to pass its tests.

---

### Task 1: `ChainedAssetProvider`

**Files:**
- Create: `app/Domain/Video/Providers/ChainedAssetProvider.php`
- Test: `tests/Unit/Domain/Video/ChainedAssetProviderTest.php`

**Interfaces:**
- Consumes: `App\Domain\Video\AssetProviderInterface` (`search(string $query, AssetSearchOptions $options): array<int, MediaAsset>`), `App\Domain\Video\AssetSearchOptions` — both already exist, unchanged.
- Produces: `App\Domain\Video\Providers\ChainedAssetProvider`, constructed as `new ChainedAssetProvider(array $providers)` where `$providers` is an ordered `array<int, AssetProviderInterface>`. Later tasks (Task 4) construct it this way.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Domain/Video/ChainedAssetProviderTest.php`:

```php
<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\AssetSearchOptions;
use App\Domain\Video\Providers\ChainedAssetProvider;
use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ChainedAssetProviderTest extends TestCase
{
    public function test_it_stops_at_the_first_provider_that_returns_a_non_empty_result(): void
    {
        $asset = new MediaAsset(['type' => MediaAssetType::Image]);

        $empty = $this->providerReturning([]);
        $winner = $this->providerReturning([$asset]);
        $shouldNotBeCalled = new class implements AssetProviderInterface
        {
            public function search(string $query, AssetSearchOptions $options): array
            {
                throw new LogicException('this provider should not have been called');
            }
        };

        $chain = new ChainedAssetProvider([$empty, $winner, $shouldNotBeCalled]);
        $results = $chain->search('query', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertSame([$asset], $results);
    }

    public function test_it_falls_through_to_the_next_provider_when_one_throws(): void
    {
        $asset = new MediaAsset(['type' => MediaAssetType::Image]);

        $throwing = new class implements AssetProviderInterface
        {
            public function search(string $query, AssetSearchOptions $options): array
            {
                throw new RuntimeException('provider unavailable');
            }
        };

        $chain = new ChainedAssetProvider([$throwing, $this->providerReturning([$asset])]);
        $results = $chain->search('query', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertSame([$asset], $results);
    }

    public function test_it_returns_an_empty_array_when_every_provider_is_empty(): void
    {
        $chain = new ChainedAssetProvider([$this->providerReturning([]), $this->providerReturning([])]);
        $results = $chain->search('query', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertSame([], $results);
    }

    public function test_it_returns_an_empty_array_when_every_provider_throws(): void
    {
        $throwing = new class implements AssetProviderInterface
        {
            public function search(string $query, AssetSearchOptions $options): array
            {
                throw new RuntimeException('provider unavailable');
            }
        };

        $chain = new ChainedAssetProvider([$throwing, $throwing]);
        $results = $chain->search('query', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertSame([], $results);
    }

    /**
     * @param  array<int, MediaAsset>  $result
     */
    private function providerReturning(array $result): AssetProviderInterface
    {
        return new class($result) implements AssetProviderInterface
        {
            public function __construct(private readonly array $result) {}

            public function search(string $query, AssetSearchOptions $options): array
            {
                return $this->result;
            }
        };
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Unit/Domain/Video/ChainedAssetProviderTest.php`
Expected: FAIL — `Class "App\Domain\Video\Providers\ChainedAssetProvider" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Domain/Video/Providers/ChainedAssetProvider.php`:

```php
<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\AssetSearchOptions;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ChainedAssetProvider implements AssetProviderInterface
{
    /**
     * @param  array<int, AssetProviderInterface>  $providers
     */
    public function __construct(private readonly array $providers) {}

    /**
     * @return array<int, \App\Models\MediaAsset>
     */
    public function search(string $query, AssetSearchOptions $options): array
    {
        foreach ($this->providers as $provider) {
            try {
                $results = $provider->search($query, $options);
            } catch (Throwable $exception) {
                Log::warning('asset provider threw during search', [
                    'provider' => $provider::class,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            if ($results !== []) {
                return $results;
            }
        }

        return [];
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Unit/Domain/Video/ChainedAssetProviderTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Video/Providers/ChainedAssetProvider.php tests/Unit/Domain/Video/ChainedAssetProviderTest.php
git commit -m "Add ChainedAssetProvider for asset-provider fallback chains"
```

---

### Task 2: Config, shared download trait, and `PixabayAssetProvider`

**Files:**
- Create: `config/assets.php`
- Modify: `.env.example`
- Create: `app/Domain/Video/Providers/Concerns/DownloadsStockAssets.php`
- Create: `app/Domain/Video/Providers/PixabayAssetProvider.php`
- Test: `tests/Unit/Domain/Video/PixabayAssetProviderTest.php`

**Interfaces:**
- Consumes: `App\Domain\Video\AssetProviderInterface`, `App\Domain\Video\AssetSearchOptions` (unchanged), `App\Models\Enums\MediaAssetType` (existing cases: `Video`, `Image`, `Audio`, `Subtitle`, `ScreenRecording`, `Thumbnail`), `App\Models\MediaAsset` (fillable: `type`, `provider`, `path`, `mime_type`, `width`, `height`, `duration`, `metadata`, `hash`).
- Produces: `App\Domain\Video\Providers\Concerns\DownloadsStockAssets` — a trait with a `protected function ingest(string $provider, string $externalId, string $downloadUrl, string $extension, MediaAssetType $type, int $width, int $height, ?int $duration, string $query): ?MediaAsset` method, consumed by both this task's `PixabayAssetProvider` and Task 3's `PexelsAssetProvider`. `App\Domain\Video\Providers\PixabayAssetProvider`, consumed by Task 4.
- Config keys produced: `assets.chain` (`array<int, string>`), `assets.providers.pixabay.api_key`, `assets.providers.pixabay.base_url`, `assets.providers.pexels.api_key`, `assets.providers.pexels.base_url` — the `pexels` keys are read by Task 3, not this task.

- [ ] **Step 1: Create the config file**

Create `config/assets.php`:

```php
<?php

return [
    // Order in which AssetServiceProvider tries providers before giving up.
    'chain' => ['pixabay', 'pexels', 'local'],

    'providers' => [
        'pixabay' => [
            'api_key' => env('PIXABAY_API_KEY'),
            'base_url' => env('PIXABAY_BASE_URL', 'https://pixabay.com/api'),
        ],
        'pexels' => [
            'api_key' => env('PEXELS_API_KEY'),
            'base_url' => env('PEXELS_BASE_URL', 'https://api.pexels.com'),
        ],
    ],
];
```

- [ ] **Step 2: Add the new environment variables**

In `.env.example`, after the existing `FFMPEG_BINARY`/`FFPROBE_BINARY` lines, add:

```
PIXABAY_API_KEY=
PIXABAY_BASE_URL=https://pixabay.com/api

PEXELS_API_KEY=
PEXELS_BASE_URL=https://api.pexels.com
```

Also add the same two `_API_KEY` lines (with real values) to the local `.env` once you have them — `.env` is not committed, so this step only touches `.env.example`.

- [ ] **Step 3: Write the failing tests for `PixabayAssetProvider`**

Create `tests/Unit/Domain/Video/PixabayAssetProviderTest.php`:

```php
<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\AssetSearchOptions;
use App\Domain\Video\Providers\PixabayAssetProvider;
use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PixabayAssetProviderTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): void
    {
        config()->set('assets.providers.pixabay.api_key', 'test-key');
        config()->set('assets.providers.pixabay.base_url', 'https://pixabay.com/api');
    }

    public function test_it_downloads_and_stores_a_matching_photo(): void
    {
        $this->configure();
        Storage::fake(config('filesystems.default'));

        Http::fake([
            'pixabay.com/api/*' => Http::response([
                'hits' => [
                    [
                        'id' => 111,
                        'largeImageURL' => 'https://cdn.pixabay.test/111.jpg',
                        'imageWidth' => 1080,
                        'imageHeight' => 1920,
                    ],
                ],
            ], 200),
            'cdn.pixabay.test/*' => Http::response('fake-jpg-bytes', 200),
        ]);

        $provider = new PixabayAssetProvider;
        $results = $provider->search('philosophy statue', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertCount(1, $results);
        $this->assertSame(MediaAssetType::Image, $results[0]->type);
        $this->assertSame('pixabay', $results[0]->provider);
        $this->assertSame('assets/stock/pixabay/111.jpg', $results[0]->path);
        Storage::disk(config('filesystems.default'))->assertExists('assets/stock/pixabay/111.jpg');
    }

    public function test_it_searches_the_video_endpoint_when_a_video_type_is_requested(): void
    {
        $this->configure();
        Storage::fake(config('filesystems.default'));

        Http::fake([
            'pixabay.com/api/videos/*' => Http::response([
                'hits' => [
                    [
                        'id' => 222,
                        'duration' => 12,
                        'videos' => [
                            'large' => ['url' => 'https://cdn.pixabay.test/222.mp4', 'width' => 1080, 'height' => 1920],
                        ],
                    ],
                ],
            ], 200),
            'cdn.pixabay.test/*' => Http::response('fake-mp4-bytes', 200),
        ]);

        $provider = new PixabayAssetProvider;
        $results = $provider->search('marble statue', new AssetSearchOptions(types: [MediaAssetType::Video]));

        $this->assertCount(1, $results);
        $this->assertSame(MediaAssetType::Video, $results[0]->type);
        $this->assertSame('assets/stock/pixabay/222.mp4', $results[0]->path);
    }

    public function test_it_returns_an_empty_array_when_the_search_request_fails(): void
    {
        $this->configure();

        Http::fake([
            'pixabay.com/api/*' => Http::response('server error', 500),
        ]);

        $provider = new PixabayAssetProvider;
        $results = $provider->search('anything', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertSame([], $results);
    }

    public function test_it_returns_an_empty_array_and_makes_no_request_for_unsupported_types(): void
    {
        $this->configure();
        Http::fake();

        $provider = new PixabayAssetProvider;
        $results = $provider->search('anything', new AssetSearchOptions(types: [MediaAssetType::Subtitle]));

        $this->assertSame([], $results);
        Http::assertNothingSent();
    }

    public function test_it_reuses_an_existing_media_asset_instead_of_downloading_again(): void
    {
        $this->configure();
        Storage::fake(config('filesystems.default'));

        $existing = MediaAsset::factory()->create([
            'type' => MediaAssetType::Image,
            'provider' => 'pixabay',
            'path' => 'assets/stock/pixabay/111.jpg',
        ]);

        Http::fake([
            'pixabay.com/api/*' => Http::response([
                'hits' => [
                    ['id' => 111, 'largeImageURL' => 'https://cdn.pixabay.test/111.jpg', 'imageWidth' => 1080, 'imageHeight' => 1920],
                ],
            ], 200),
        ]);

        $provider = new PixabayAssetProvider;
        $results = $provider->search('philosophy statue', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertCount(1, $results);
        $this->assertSame($existing->id, $results[0]->id);
        Http::assertSentCount(1);
    }

    public function test_it_excludes_given_asset_ids(): void
    {
        $this->configure();
        Storage::fake(config('filesystems.default'));

        $existing = MediaAsset::factory()->create([
            'type' => MediaAssetType::Image,
            'provider' => 'pixabay',
            'path' => 'assets/stock/pixabay/111.jpg',
        ]);

        Http::fake([
            'pixabay.com/api/*' => Http::response([
                'hits' => [
                    ['id' => 111, 'largeImageURL' => 'https://cdn.pixabay.test/111.jpg', 'imageWidth' => 1080, 'imageHeight' => 1920],
                ],
            ], 200),
        ]);

        $provider = new PixabayAssetProvider;
        $results = $provider->search('philosophy statue', new AssetSearchOptions(
            types: [MediaAssetType::Image],
            excludeAssetIds: [$existing->id],
        ));

        $this->assertSame([], $results);
    }
}
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `php artisan test tests/Unit/Domain/Video/PixabayAssetProviderTest.php`
Expected: FAIL — `Class "App\Domain\Video\Providers\PixabayAssetProvider" not found`.

- [ ] **Step 5: Write the shared download/dedup trait**

Create `app/Domain/Video/Providers/Concerns/DownloadsStockAssets.php`:

```php
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
```

- [ ] **Step 6: Write `PixabayAssetProvider`**

Create `app/Domain/Video/Providers/PixabayAssetProvider.php`:

```php
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
        $wantsVideo = array_intersect($options->types, [MediaAssetType::Video, MediaAssetType::ScreenRecording]) !== [];
        $wantsImage = array_intersect($options->types, [MediaAssetType::Image, MediaAssetType::Thumbnail]) !== [];

        if (! $wantsVideo && ! $wantsImage) {
            return [];
        }

        $hits = $wantsVideo
            ? $this->searchVideos($query, $options->maxResults)
            : $this->searchPhotos($query, $options->maxResults);

        $assets = [];

        foreach ($hits as $hit) {
            $asset = $this->ingest(
                provider: 'pixabay',
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
            ->map(fn (array $hit): array => [
                'id' => $hit['id'],
                'url' => $hit['largeImageURL'],
                'extension' => 'jpg',
                'width' => $hit['imageWidth'] ?? 0,
                'height' => $hit['imageHeight'] ?? 0,
            ])
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

                return [
                    'id' => $hit['id'],
                    'url' => $file['url'],
                    'extension' => 'mp4',
                    'width' => $file['width'] ?? 0,
                    'height' => $file['height'] ?? 0,
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
        try {
            $response = Http::baseUrl(config('assets.providers.pixabay.base_url'))
                ->timeout(15)
                ->get($path, [...$query, 'key' => config('assets.providers.pixabay.api_key')]);
        } catch (Throwable $exception) {
            Log::warning('pixabay search request failed', ['error' => $exception->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('pixabay search request failed', ['status' => $response->status()]);

            return null;
        }

        return $response->json();
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test tests/Unit/Domain/Video/PixabayAssetProviderTest.php`
Expected: PASS (6 tests).

- [ ] **Step 8: Commit**

```bash
git add config/assets.php .env.example app/Domain/Video/Providers/Concerns/DownloadsStockAssets.php app/Domain/Video/Providers/PixabayAssetProvider.php tests/Unit/Domain/Video/PixabayAssetProviderTest.php
git commit -m "Add Pixabay stock asset provider with shared download/dedup trait"
```

---

### Task 3: `PexelsAssetProvider`

**Files:**
- Create: `app/Domain/Video/Providers/PexelsAssetProvider.php`
- Test: `tests/Unit/Domain/Video/PexelsAssetProviderTest.php`

**Interfaces:**
- Consumes: `App\Domain\Video\Providers\Concerns\DownloadsStockAssets` (from Task 2, `protected function ingest(...): ?MediaAsset`, identical signature), `config('assets.providers.pexels.api_key')` / `config('assets.providers.pexels.base_url')` (already defined in `config/assets.php` from Task 2).
- Produces: `App\Domain\Video\Providers\PexelsAssetProvider`, consumed by Task 4.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Domain/Video/PexelsAssetProviderTest.php`:

```php
<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\AssetSearchOptions;
use App\Domain\Video\Providers\PexelsAssetProvider;
use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PexelsAssetProviderTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): void
    {
        config()->set('assets.providers.pexels.api_key', 'test-key');
        config()->set('assets.providers.pexels.base_url', 'https://api.pexels.com');
    }

    public function test_it_downloads_and_stores_a_matching_photo(): void
    {
        $this->configure();
        Storage::fake(config('filesystems.default'));

        Http::fake([
            'api.pexels.com/v1/search*' => Http::response([
                'photos' => [
                    [
                        'id' => 333,
                        'width' => 1080,
                        'height' => 1920,
                        'src' => ['original' => 'https://cdn.pexels.test/333.jpg'],
                    ],
                ],
            ], 200),
            'cdn.pexels.test/*' => Http::response('fake-jpg-bytes', 200),
        ]);

        $provider = new PexelsAssetProvider;
        $results = $provider->search('stoic statue', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertCount(1, $results);
        $this->assertSame(MediaAssetType::Image, $results[0]->type);
        $this->assertSame('pexels', $results[0]->provider);
        $this->assertSame('assets/stock/pexels/333.jpg', $results[0]->path);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'test-key');
        });
    }

    public function test_it_picks_the_largest_video_file_when_a_video_type_is_requested(): void
    {
        $this->configure();
        Storage::fake(config('filesystems.default'));

        Http::fake([
            'api.pexels.com/videos/search*' => Http::response([
                'videos' => [
                    [
                        'id' => 444,
                        'duration' => 9,
                        'video_files' => [
                            ['link' => 'https://cdn.pexels.test/444-small.mp4', 'width' => 360, 'height' => 640],
                            ['link' => 'https://cdn.pexels.test/444-large.mp4', 'width' => 1080, 'height' => 1920],
                        ],
                    ],
                ],
            ], 200),
            'cdn.pexels.test/*' => Http::response('fake-mp4-bytes', 200),
        ]);

        $provider = new PexelsAssetProvider;
        $results = $provider->search('marble statue', new AssetSearchOptions(types: [MediaAssetType::Video]));

        $this->assertCount(1, $results);
        $this->assertSame(MediaAssetType::Video, $results[0]->type);
        $this->assertSame('assets/stock/pexels/444.mp4', $results[0]->path);
    }

    public function test_it_returns_an_empty_array_when_the_search_request_fails(): void
    {
        $this->configure();

        Http::fake([
            'api.pexels.com/v1/search*' => Http::response('unauthorized', 401),
        ]);

        $provider = new PexelsAssetProvider;
        $results = $provider->search('anything', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertSame([], $results);
    }

    public function test_it_returns_an_empty_array_and_makes_no_request_for_unsupported_types(): void
    {
        $this->configure();
        Http::fake();

        $provider = new PexelsAssetProvider;
        $results = $provider->search('anything', new AssetSearchOptions(types: [MediaAssetType::Subtitle]));

        $this->assertSame([], $results);
        Http::assertNothingSent();
    }

    public function test_it_reuses_an_existing_media_asset_instead_of_downloading_again(): void
    {
        $this->configure();
        Storage::fake(config('filesystems.default'));

        $existing = MediaAsset::factory()->create([
            'type' => MediaAssetType::Image,
            'provider' => 'pexels',
            'path' => 'assets/stock/pexels/333.jpg',
        ]);

        Http::fake([
            'api.pexels.com/v1/search*' => Http::response([
                'photos' => [
                    ['id' => 333, 'width' => 1080, 'height' => 1920, 'src' => ['original' => 'https://cdn.pexels.test/333.jpg']],
                ],
            ], 200),
        ]);

        $provider = new PexelsAssetProvider;
        $results = $provider->search('stoic statue', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertCount(1, $results);
        $this->assertSame($existing->id, $results[0]->id);
        Http::assertSentCount(1);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Unit/Domain/Video/PexelsAssetProviderTest.php`
Expected: FAIL — `Class "App\Domain\Video\Providers\PexelsAssetProvider" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Domain/Video/Providers/PexelsAssetProvider.php`:

```php
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
        $wantsVideo = array_intersect($options->types, [MediaAssetType::Video, MediaAssetType::ScreenRecording]) !== [];
        $wantsImage = array_intersect($options->types, [MediaAssetType::Image, MediaAssetType::Thumbnail]) !== [];

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
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Unit/Domain/Video/PexelsAssetProviderTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Video/Providers/PexelsAssetProvider.php tests/Unit/Domain/Video/PexelsAssetProviderTest.php
git commit -m "Add Pexels stock asset provider"
```

---

### Task 4: Wire `AssetServiceProvider` to the chain

**Files:**
- Modify: `app/Providers/AssetServiceProvider.php`
- Test: `tests/Feature/Domain/Video/AssetServiceProviderTest.php`

**Interfaces:**
- Consumes: `App\Domain\Video\Providers\ChainedAssetProvider` (Task 1), `App\Domain\Video\Providers\PixabayAssetProvider` (Task 2), `App\Domain\Video\Providers\PexelsAssetProvider` (Task 3), `App\Domain\Video\Providers\LocalAssetProvider` (pre-existing), `config('assets.chain')` (Task 2, default `['pixabay', 'pexels', 'local']`).
- Produces: `AssetProviderInterface` now resolves to a `ChainedAssetProvider` everywhere in the app (Filament actions, `CollectVideoAssetsJob`, etc. — no call site changes needed).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Domain/Video/AssetServiceProviderTest.php`:

```php
<?php

namespace Tests\Feature\Domain\Video;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\Providers\ChainedAssetProvider;
use Tests\TestCase;

class AssetServiceProviderTest extends TestCase
{
    public function test_asset_provider_interface_resolves_to_a_chained_provider(): void
    {
        $this->assertInstanceOf(ChainedAssetProvider::class, $this->app->make(AssetProviderInterface::class));
    }

    public function test_the_chain_falls_back_to_local_results_when_stock_providers_are_unconfigured(): void
    {
        // No PIXABAY_API_KEY/PEXELS_API_KEY in the test environment, so both
        // remote providers' searches fail closed (empty array) and the chain
        // must still resolve — proving `local` is always last in the chain.
        $provider = $this->app->make(AssetProviderInterface::class);

        $results = $provider->search('this query matches nothing', new \App\Domain\Video\AssetSearchOptions(
            types: [\App\Models\Enums\MediaAssetType::Image],
        ));

        $this->assertIsArray($results);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Domain/Video/AssetServiceProviderTest.php`
Expected: FAIL — `AssetProviderInterface` still resolves to `LocalAssetProvider`, so `assertInstanceOf(ChainedAssetProvider::class, ...)` fails.

- [ ] **Step 3: Rewire the service provider**

Replace the contents of `app/Providers/AssetServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\Providers\ChainedAssetProvider;
use App\Domain\Video\Providers\LocalAssetProvider;
use App\Domain\Video\Providers\PexelsAssetProvider;
use App\Domain\Video\Providers\PixabayAssetProvider;
use Illuminate\Support\ServiceProvider;

class AssetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AssetProviderInterface::class, function (): ChainedAssetProvider {
            $drivers = [
                'pixabay' => fn (): PixabayAssetProvider => new PixabayAssetProvider,
                'pexels' => fn (): PexelsAssetProvider => new PexelsAssetProvider,
                'local' => fn (): LocalAssetProvider => new LocalAssetProvider,
            ];

            $providers = array_map(
                fn (string $name) => $drivers[$name](),
                config('assets.chain', ['local']),
            );

            return new ChainedAssetProvider($providers);
        });
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Domain/Video/AssetServiceProviderTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions (this also re-runs the pre-existing `LocalAssetProviderTest` and `CollectVideoAssetsServiceTest`, which construct `LocalAssetProvider`/`FakeAssetProvider` directly and are unaffected by the rebind).

- [ ] **Step 6: Run Pint**

Run: `vendor/bin/pint --test`
Expected: no style violations. If it reports any, run `vendor/bin/pint` (without `--test`) to auto-fix, then re-run `php artisan test`.

- [ ] **Step 7: Commit**

```bash
git add app/Providers/AssetServiceProvider.php tests/Feature/Domain/Video/AssetServiceProviderTest.php
git commit -m "Bind AssetProviderInterface to the Pixabay/Pexels/local fallback chain"
```

---

## After this plan lands

`PIXABAY_API_KEY`/`PEXELS_API_KEY` are still blank in the real `.env` — every task above is verified with `Http::fake()`, no live key required. Once the product owner registers at pixabay.com/api and pexels.com/api and provides both keys:

1. Add them to `.env` (`PIXABAY_API_KEY=...`, `PEXELS_API_KEY=...`).
2. Run `php artisan config:clear` (env changes aren't picked up by a already-booted `queue:work` process — restart it too: find it with `ps aux | grep queue:work`, kill it, relaunch with the same command).
3. Do one real dashboard run: Filament → Videos → "Generate Video" with a brand-new topic → confirm `CollectVideoAssetsJob` completes without `AssetNotFoundException` and the scenes' `asset_id` point at freshly downloaded `assets/stock/pixabay/...` or `assets/stock/pexels/...` files.

This live check is manual (no unit test can call the real Pixabay/Pexels APIs) and isn't part of the task checklist above.
