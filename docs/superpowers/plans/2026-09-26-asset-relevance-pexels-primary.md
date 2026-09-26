# Asset Relevance Fix: Pexels-Primary + Relevance Filter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop the video pipeline from attaching visually irrelevant stock
photos to scenes (confirmed real cases: a moss-covered Buddha statue for
an "Epictetus statue" query, a generic glamour photo of a woman for
"person writing", a smoke photo for an abstract transition query) by (1)
trying Pexels before Pixabay in the asset provider chain — empirically
verified far more relevant for this content domain — and (2) rejecting
stock-provider hits whose own real tags/description share nothing with
the search query, instead of blindly accepting whatever the API's first
result is.

**Architecture:** No new services, no new interfaces. `config/assets.php`'s
provider chain order changes from `[wikimedia, pixabay, pexels, local]` to
`[wikimedia, pexels, pixabay, local]`. The shared `DownloadsStockAssets`
trait (used by both `PixabayAssetProvider` and `PexelsAssetProvider`) gains
a `description`-aware `ingest()` (stores the provider's real tags/alt text
as `metadata.tags`, not an echo of the search query — the current stored
"tags" are misleading, they're just the query tokenized) and a new
`isRelevant(string $query, string $description): bool` guard, called by
each provider's `search()` loop before downloading a candidate. A hit is
rejected only when the provider sent real description text AND it shares
no significant word with the query (after stripping generic medium/style
words like "statue"/"bust"/"portrait" that `GenerateScenesService`'s own
prompt tells the LLM to always append — every stock photo's tags contain
words like that regardless of subject, so checking them would defeat the
filter). Both providers already fetch 3+ candidates per search
(`per_page => max(3, $maxResults)`) but only ever look at the first one —
rejected hits now fall through to the next already-fetched candidate
before the provider gives up and the chain moves to the next provider.
`WikimediaAssetProvider` is untouched: it already returns zero hits rather
than bad ones for queries it can't match (verified live against the real
Wikimedia Commons API during investigation), so there's no evidence it
needs this guard.

**Tech Stack:** Laravel 12 / PHP 8.4, `Illuminate\Support\Facades\Http`
(already used by both providers, faked in tests via `Http::fake()`),
PHPUnit Unit tests.

**Spec:** No separate spec file — bounded change approved in chat directly
(2026-09-25/26): root cause investigated and confirmed with real API
calls (Pixabay's own `tags` field for "Epictetus statue" was literally
"buddha, statue, moss, buddha purnima, sculpture... japan, buddhism" — zero
relation to Epictetus) and a live side-by-side comparison against Pexels
for the same failing queries (Pexels returned genuinely relevant classical
statue/writing-hand photos for every one of them). Confirmed via Pexels'
own docs page that its API has no paid tier — free for this use case.

## Global Constraints

- No new config keys beyond reordering the existing `assets.chain` array.
- No changes to `WikimediaAssetProvider`, `LocalAssetProvider`,
  `ChainedAssetProvider`, `AssetServiceProvider`, or any Job/pipeline class.
- A blank/missing description from a provider must default to **accept**
  (not reject) — we only reject when we have real evidence of a mismatch.
  This is also what keeps every existing test fixture (none of which set a
  `tags`/`alt` field, since this is new behavior) passing unmodified.
- Tests make zero real external HTTP calls — only `Http::fake()`, per
  `docs/testing.md`.
- Style: `vendor/bin/pint --test` clean, Laravel defaults.

---

### Task 1: Pexels-primary chain + shared relevance filter

**Files:**
- Modify: `config/assets.php`
- Modify: `app/Domain/Video/Providers/Concerns/DownloadsStockAssets.php`
- Modify: `app/Domain/Video/Providers/PixabayAssetProvider.php`
- Modify: `app/Domain/Video/Providers/PexelsAssetProvider.php`
- Test: `tests/Unit/Domain/Video/PixabayAssetProviderTest.php`
- Test: `tests/Unit/Domain/Video/PexelsAssetProviderTest.php`

**Interfaces:**
- Consumes: nothing new — `MediaAsset`, `AssetSearchOptions`,
  `AssetProviderInterface` are all unchanged.
- Produces: `DownloadsStockAssets::ingest()` gains a new **required**
  trailing parameter `string $description` (both call sites in this task
  update together, so there is no intermediate broken state). Produces
  `DownloadsStockAssets::isRelevant(string $query, string $description):
  bool` (`protected`, called by both providers' `search()` loops before
  `ingest()`). No other task in this plan depends on these — this is the
  only task.

- [ ] **Step 1: Write the failing tests**

Open `tests/Unit/Domain/Video/PixabayAssetProviderTest.php` and add these
two test methods to the `PixabayAssetProviderTest` class (e.g. right
after `test_it_downloads_and_stores_a_matching_photo`):

```php
    public function test_it_skips_a_hit_whose_tags_share_nothing_with_the_query_and_uses_the_next_one(): void
    {
        $this->configure();
        Storage::fake(config('filesystems.default'));

        Http::fake([
            'pixabay.com/api/*' => Http::response([
                'hits' => [
                    // Real bug reproduction: Pixabay's own top hit for "Epictetus
                    // statue" was a Buddha statue tagged with nothing related.
                    [
                        'id' => 378137,
                        'tags' => 'buddha, statue, moss, buddha purnima, sculpture, japan, buddhism',
                        'largeImageURL' => 'https://cdn.pixabay.test/378137.jpg',
                        'imageWidth' => 1080,
                        'imageHeight' => 1920,
                    ],
                    [
                        'id' => 999,
                        'tags' => 'epictetus, stoic philosopher, ancient greek bust',
                        'largeImageURL' => 'https://cdn.pixabay.test/999.jpg',
                        'imageWidth' => 1080,
                        'imageHeight' => 1920,
                    ],
                ],
            ], 200),
            'cdn.pixabay.test/*' => Http::response('fake-jpg-bytes', 200),
        ]);

        $provider = new PixabayAssetProvider;
        $results = $provider->search('Epictetus statue', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertCount(1, $results);
        $this->assertSame('assets/stock/pixabay/999.jpg', $results[0]->path);
        Storage::disk(config('filesystems.default'))->assertMissing('assets/stock/pixabay/378137.jpg');
    }

    public function test_it_stores_the_providers_real_tags_not_the_search_query(): void
    {
        $this->configure();
        Storage::fake(config('filesystems.default'));

        Http::fake([
            'pixabay.com/api/*' => Http::response([
                'hits' => [
                    [
                        'id' => 111,
                        'tags' => 'epictetus, marble, sculpture',
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
        $this->assertSame(['epictetus', 'marble', 'sculpture'], $results[0]->metadata['tags']);
    }
```

Open `tests/Unit/Domain/Video/PexelsAssetProviderTest.php` and add these
two test methods to the `PexelsAssetProviderTest` class (e.g. right after
`test_it_downloads_and_stores_a_matching_photo`):

```php
    public function test_it_skips_a_hit_whose_alt_text_shares_nothing_with_the_query_and_uses_the_next_one(): void
    {
        $this->configure();
        Storage::fake(config('filesystems.default'));

        Http::fake([
            'api.pexels.com/v1/search*' => Http::response([
                'photos' => [
                    [
                        'id' => 333,
                        'alt' => 'A red sports car parked on a city street at night.',
                        'width' => 1080,
                        'height' => 1920,
                        'src' => ['original' => 'https://cdn.pexels.test/333.jpg'],
                    ],
                    [
                        'id' => 555,
                        'alt' => 'Detailed close-up of an ancient Greek-style stone statue.',
                        'width' => 1080,
                        'height' => 1920,
                        'src' => ['original' => 'https://cdn.pexels.test/555.jpg'],
                    ],
                ],
            ], 200),
            'cdn.pexels.test/*' => Http::response('fake-jpg-bytes', 200),
        ]);

        $provider = new PexelsAssetProvider;
        $results = $provider->search('ancient statue', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertCount(1, $results);
        $this->assertSame('assets/stock/pexels/555.jpg', $results[0]->path);
        Storage::disk(config('filesystems.default'))->assertMissing('assets/stock/pexels/333.jpg');
    }

    public function test_it_stores_the_providers_real_alt_text_as_tags_not_the_search_query(): void
    {
        $this->configure();
        Storage::fake(config('filesystems.default'));

        Http::fake([
            'api.pexels.com/v1/search*' => Http::response([
                'photos' => [
                    [
                        'id' => 333,
                        'alt' => 'Bust of Marcus Aurelius on exhibit',
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
        $this->assertSame(['bust', 'of', 'marcus', 'aurelius', 'on', 'exhibit'], $results[0]->metadata['tags']);
    }
```

- [ ] **Step 2: Run the tests and verify they fail**

Run: `php artisan test --filter=PixabayAssetProviderTest`
Run: `php artisan test --filter=PexelsAssetProviderTest`
Expected: the 2 new tests in each file FAIL (the "skips" tests fail because
today's code has no relevance check and downloads the first hit; the
"stores the real tags" tests fail because `metadata.tags` currently holds
the tokenized search query, not the provider's real tags/alt text). All
pre-existing tests in both files still PASS.

- [ ] **Step 3: Reorder the provider chain**

In `config/assets.php`, replace:

```php
    // Order in which AssetServiceProvider tries providers before giving up.
    // wikimedia goes first: it's the only source with real classical art
    // (paintings, statues, sculptures) for historical/philosophical scenes,
    // and returns no hits for queries it has nothing relevant for, so the
    // chain falls through to the commercial stock providers as before.
    'chain' => ['wikimedia', 'pixabay', 'pexels', 'local'],
```

with:

```php
    // Order in which AssetServiceProvider tries providers before giving up.
    // wikimedia goes first: it's the only source with real classical art
    // (paintings, statues, sculptures) for historical/philosophical scenes,
    // and returns no hits for queries it has nothing relevant for, so the
    // chain falls through to the commercial stock providers as before.
    // pexels goes before pixabay: verified live against real failing
    // queries (2026-09-25) that Pexels' own search ranking returns far
    // more relevant results for this app's historical/philosophical
    // content than Pixabay's — e.g. Pixabay's top hit for "Epictetus
    // statue" was a Buddha statue (tags: buddha, moss, zen, japan),
    // Pexels' was a real ancient Greek/Roman statue photo. Pixabay stays
    // as a fallback, now behind the same relevance filter as Pexels
    // (see DownloadsStockAssets::isRelevant()).
    'chain' => ['wikimedia', 'pexels', 'pixabay', 'local'],
```

- [ ] **Step 4: Update the shared `DownloadsStockAssets` trait**

Replace the full contents of `app/Domain/Video/Providers/Concerns/DownloadsStockAssets.php`:

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
        string $description,
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
                'metadata' => ['source' => $provider, 'query' => $query, 'external_id' => $externalId, 'tags' => $this->tagsFor($description)],
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
```

- [ ] **Step 5: Update `PixabayAssetProvider`**

Replace the full contents of `app/Domain/Video/Providers/PixabayAssetProvider.php`:

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
            if (! $this->isRelevant($query, $hit['description'])) {
                continue;
            }

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
                description: $hit['description'],
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
     * @return array<int, array{id: int, url: string, extension: string, width: int, height: int, description: string}>
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
                    'description' => $hit['tags'] ?? '',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, url: string, extension: string, width: int, height: int, duration: ?int, description: string}>
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
                    'description' => $hit['tags'] ?? '',
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
```

- [ ] **Step 6: Update `PexelsAssetProvider`**

Replace the full contents of `app/Domain/Video/Providers/PexelsAssetProvider.php`:

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
        $typeValues = array_map(fn (MediaAssetType $type): string => $type->value, $options->types);
        $wantsVideo = array_intersect($typeValues, [MediaAssetType::Video->value, MediaAssetType::ScreenRecording->value]) !== [];
        $wantsImage = array_intersect($typeValues, [MediaAssetType::Image->value, MediaAssetType::Thumbnail->value]) !== [];

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
            if (! $this->isRelevant($query, $hit['description'])) {
                continue;
            }

            $asset = $this->ingest(
                provider: 'pexels',
                externalId: (string) $hit['id'],
                downloadUrl: $hit['url'],
                extension: $hit['extension'],
                type: $hitType,
                width: $hit['width'],
                height: $hit['height'],
                duration: $hit['duration'] ?? null,
                query: $query,
                description: $hit['description'],
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
     * @return array<int, array{id: int, url: string, extension: string, width: int, height: int, description: string}>
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
            ->map(function (array $photo): ?array {
                if (blank($photo['src']['original'] ?? null)) {
                    return null;
                }

                return [
                    'id' => $photo['id'],
                    'url' => $photo['src']['original'],
                    'extension' => 'jpg',
                    'width' => $photo['width'] ?? 0,
                    'height' => $photo['height'] ?? 0,
                    'description' => $photo['alt'] ?? '',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, url: string, extension: string, width: int, height: int, duration: ?int, description: string}>
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
                    // Pexels video hits rarely populate `tags`; the human-readable
                    // URL slug (e.g. ".../ancient-marble-sculpture-bust-in-museum-30129839")
                    // is usually the only real description text available, and
                    // tagsFor()'s tokenizer already splits on `/`/`-` like any
                    // other non-letter separator.
                    'description' => trim(implode(' ', $video['tags'] ?? []).' '.($video['url'] ?? '')),
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
        $apiKey = config('assets.providers.pexels.api_key');

        if (blank($apiKey)) {
            return null;
        }

        try {
            $response = Http::baseUrl(config('assets.providers.pexels.base_url'))
                ->withHeaders(['Authorization' => $apiKey])
                ->timeout(15)
                ->get($path, $query);
        } catch (Throwable $exception) {
            Log::channel('video')->warning('pexels search request failed', ['error' => $exception->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::channel('video')->warning('pexels search request failed', ['status' => $response->status()]);

            return null;
        }

        return $response->json();
    }
}
```

- [ ] **Step 7: Run the tests and verify they pass**

Run: `php artisan test --filter=PixabayAssetProviderTest`
Run: `php artisan test --filter=PexelsAssetProviderTest`
Expected: PASS (9/9 in Pixabay's file, 7/7 in Pexels' file — the original
tests plus the 2 new ones in each).

Also run the full suite to confirm nothing elsewhere regressed:

Run: `php artisan test`
Expected: PASS, same count as baseline plus the 4 new tests.

- [ ] **Step 8: Pint + commit**

Run: `vendor/bin/pint config/assets.php app/Domain/Video/Providers/Concerns/DownloadsStockAssets.php app/Domain/Video/Providers/PixabayAssetProvider.php app/Domain/Video/Providers/PexelsAssetProvider.php tests/Unit/Domain/Video/PixabayAssetProviderTest.php tests/Unit/Domain/Video/PexelsAssetProviderTest.php`

```bash
git add config/assets.php app/Domain/Video/Providers/Concerns/DownloadsStockAssets.php app/Domain/Video/Providers/PixabayAssetProvider.php app/Domain/Video/Providers/PexelsAssetProvider.php tests/Unit/Domain/Video/PixabayAssetProviderTest.php tests/Unit/Domain/Video/PexelsAssetProviderTest.php
git commit -m "fix(video): prefer Pexels over Pixabay and reject irrelevant stock-asset matches"
```

---

## Post-implementation verification (controller, not a subagent task)

After the task is reviewed and merged:

1. `php artisan test`, `vendor/bin/pint --test` — no regressions.
2. Real end-to-end verification: generate a new video for an existing
   philosophy-niche `ContentProject` (real, non-Fake providers) and
   confirm its scenes' assets come from `provider=pexels` where a
   Pexels match exists, and that the images are actually thematically
   relevant to their `visual_query` (visually inspect a few via `Read`).
