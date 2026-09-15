# Phase 3c — Assets Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** From a `Video` with a generated `Voiceover` (Phase 3b), fill in `VideoScene.asset_id` for every scene that needs a visual, by matching each scene's `visual_query` against a local library of `MediaAsset` rows — closing the Assets slice of Phase 3's DoD and moving `Video.status` to `AssetsReady`, with no dependency on any real stock-media API.

**Architecture:** A new `AssetProviderInterface` (mirrors the one-method shape of `TtsProviderInterface`/`LlmProviderInterface`) bound directly to `LocalAssetProvider` — no manager layer, since there's only one provider in the MVP. `LocalAssetProvider` scores existing `MediaAsset` rows by word-overlap between the search query and `metadata.tags`, filtered by asset type and an exclude list. `app/Domain/Video/Services/CollectVideoAssetsService` walks a video's scenes, maps each scene's type to the asset types it can use, asks the provider for a match (excluding assets already used elsewhere in the same video, with a fallback that allows reuse if the library is too small), and returns a `scene_id => asset_id` map — no DB writes, mirroring `GenerateScenesService`/`GenerateVoiceoverService`. `app/Jobs/CollectVideoAssetsJob` mirrors `GenerateVoiceoverJob`'s idempotency pattern (`ShouldBeUnique` + status guard), persists the assignments and the `Video.status` transition together in one transaction. A Filament row action on `VideosTable` triggers it, and `MediaAssetForm` gains an editable tags field so the local library can actually be populated through the admin panel.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL, `filament/filament` v4.13 (`Filament\Forms\Components\TagsInput` for nested JSON array editing).

**Spec:** `docs/superpowers/specs/2026-09-15-phase3c-assets-design.md`

## Global Constraints

- `AssetProviderInterface::search(string $query, AssetSearchOptions $options): array` returns `array<int, MediaAsset>` — already-persisted models, not a separate DTO (spec, `AssetProviderInterface`).
- `AssetSearchOptions::__construct(array $types, int $maxResults = 5, array $excludeAssetIds = [])` — `$types` is `array<int, MediaAssetType>` (spec, same section).
- `AssetServiceProvider` binds `AssetProviderInterface::class` straight to `LocalAssetProvider::class`, registered in `bootstrap/providers.php` (spec, Скоуп).
- `LocalAssetProvider`: filters `media_assets` by `type` (mapped to `->value` for the query) and excludes `excludeAssetIds`, scores each candidate by the count of case-insensitive word overlap between the query and `metadata.tags`, drops zero-score candidates entirely, returns highest-score first, capped at `maxResults`. Base query is ordered by `id` before scoring so equal-score ties resolve deterministically (spec, `LocalAssetProvider`, refined here for test determinism — PHP 8's sort is stable but Postgres row order without `ORDER BY` is not).
- `FakeAssetProvider::respondWith(array $pool): static` sets an in-memory candidate pool; `search()` filters that pool by `$options->types` and `$options->excludeAssetIds` (real filtering, not tag scoring) — this is what makes `CollectVideoAssetsService`'s dedup/fallback logic testable deterministically (spec's stated design intent — "respondWith-стиль" — applied here with just enough behavior to exercise the service).
- `CollectVideoAssetsService::__construct(AssetProviderInterface $assetProvider)`, `CollectVideoAssetsService::collect(Video $video): array<int, int>` (scene_id => media_asset_id) — no DB writes; the job persists (spec, `CollectVideoAssetsService`).
- Scenes are skipped when `visual_query === null` or `asset_id !== null` already (spec, same section).
- Scene type → asset type mapping: `ScreenRecording` → `[ScreenRecording]`; `Screenshot`, `Image` → `[Image]`; everything else → `[Video, Image]` (spec, `typesFor()`).
- Dedup: first search excludes assets already assigned elsewhere in this video; if that returns empty AND at least one asset was already used, retry once without excluding; if still empty, throw `App\Domain\Video\Exceptions\AssetNotFoundException` (spec, same section).
- `CollectVideoAssetsJob`: `$timeout = 120`, `$tries = 3`, `backoff() = [10, 30, 60]`, `ShouldBeUnique` keyed by `video_id`, `uniqueFor = 200` (spec, `CollectVideoAssetsJob`).
- `handle()` order: guard (`status !== VoiceGenerated` → no-op) → `CollectVideoAssetsService::collect()` outside any transaction (may throw) → `DB::transaction()` updating each `VideoScene.asset_id` then `Video.status = AssetsReady` (spec, same section).
- No second "already done" guard is needed (unlike 3a/3b) — `VoiceGenerated` already excludes a video that has moved to `AssetsReady`, and `collect()` itself skips already-assigned scenes (spec, same section).
- `failed()` only logs to the `video` channel — no DB state change (spec, same section, same convention as `GenerateScenesJob`/`GenerateVoiceoverJob`).
- "Collect Assets" row action on `VideosTable`, visible when `Video.status === VideoStatus::VoiceGenerated` (spec, `Filament`).
- `MediaAssetForm`: the `metadata` field becomes `TagsInput::make('metadata.tags')` (dot-path resolves directly into the `metadata` array cast — same idiom already used for `ContentProject.settings.ai.*`, no custom hydrate/dehydrate hooks needed); `provider` `TextInput` gets `->default('local')` (spec, `Filament`).
- No migrations in this phase — `media_assets.metadata` and `video_scenes.asset_id` already exist from Phase 1 (spec, Скоуп).
- No changes to `VideoResource`/`VideoSceneResource`/`MediaAssetResource`'s table/pages beyond the form field above — still auto-generated CRUD otherwise (spec, Скоуп — не входить).

---

### Task 1: Asset provider contracts — `AssetProviderInterface`, `LocalAssetProvider`, `FakeAssetProvider`

**Files:**
- Create: `app/Domain/Video/AssetSearchOptions.php`
- Create: `app/Domain/Video/AssetProviderInterface.php`
- Create: `app/Domain/Video/Providers/LocalAssetProvider.php`
- Create: `app/Domain/Video/Providers/FakeAssetProvider.php`
- Create: `app/Providers/AssetServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Test: `tests/Unit/Domain/Video/LocalAssetProviderTest.php`

**Interfaces:**
- Consumes: `App\Models\MediaAsset` (`type` cast to `App\Models\Enums\MediaAssetType`, `metadata` array cast, both from Phase 1).
- Produces: `AssetProviderInterface::search(string $query, AssetSearchOptions $options): array<int, MediaAsset>`, `AssetSearchOptions::__construct(array $types, int $maxResults = 5, array $excludeAssetIds = [])`, `FakeAssetProvider::respondWith(array $pool): static` — all consumed by Task 2's `CollectVideoAssetsService` and its tests.

- [ ] **Step 1: Create the DTO and the interface**

`app/Domain/Video/AssetSearchOptions.php`:

```php
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
```

`app/Domain/Video/AssetProviderInterface.php`:

```php
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
```

There is no test to run for this step — these are plain value types with no behavior. Proceed straight to Step 2.

- [ ] **Step 2: Write the failing tests for `LocalAssetProvider`**

`tests/Unit/Domain/Video/LocalAssetProviderTest.php`:

```php
<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\AssetSearchOptions;
use App\Domain\Video\Providers\LocalAssetProvider;
use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalAssetProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_asset_with_the_highest_tag_overlap_first(): void
    {
        $weak = MediaAsset::factory()->create([
            'type' => MediaAssetType::Video,
            'metadata' => ['tags' => ['server']],
        ]);
        $strong = MediaAsset::factory()->create([
            'type' => MediaAssetType::Video,
            'metadata' => ['tags' => ['ai', 'server', 'room']],
        ]);

        $provider = new LocalAssetProvider;
        $results = $provider->search('AI server room', new AssetSearchOptions(types: [MediaAssetType::Video]));

        $this->assertCount(2, $results);
        $this->assertSame($strong->id, $results[0]->id);
        $this->assertSame($weak->id, $results[1]->id);
    }

    public function test_it_filters_by_type(): void
    {
        MediaAsset::factory()->create([
            'type' => MediaAssetType::Image,
            'metadata' => ['tags' => ['ai', 'server']],
        ]);
        $video = MediaAsset::factory()->create([
            'type' => MediaAssetType::Video,
            'metadata' => ['tags' => ['ai', 'server']],
        ]);

        $provider = new LocalAssetProvider;
        $results = $provider->search('ai server', new AssetSearchOptions(types: [MediaAssetType::Video]));

        $this->assertCount(1, $results);
        $this->assertSame($video->id, $results[0]->id);
    }

    public function test_it_excludes_given_asset_ids(): void
    {
        $first = MediaAsset::factory()->create([
            'type' => MediaAssetType::Video,
            'metadata' => ['tags' => ['ai']],
        ]);
        $second = MediaAsset::factory()->create([
            'type' => MediaAssetType::Video,
            'metadata' => ['tags' => ['ai']],
        ]);

        $provider = new LocalAssetProvider;
        $results = $provider->search('ai', new AssetSearchOptions(
            types: [MediaAssetType::Video],
            excludeAssetIds: [$first->id],
        ));

        $this->assertCount(1, $results);
        $this->assertSame($second->id, $results[0]->id);
    }

    public function test_it_returns_an_empty_array_when_nothing_matches(): void
    {
        MediaAsset::factory()->create([
            'type' => MediaAssetType::Video,
            'metadata' => ['tags' => ['cat']],
        ]);

        $provider = new LocalAssetProvider;
        $results = $provider->search('server room', new AssetSearchOptions(types: [MediaAssetType::Video]));

        $this->assertSame([], $results);
    }

    public function test_it_respects_max_results(): void
    {
        MediaAsset::factory()->count(3)->create([
            'type' => MediaAssetType::Video,
            'metadata' => ['tags' => ['ai']],
        ]);

        $provider = new LocalAssetProvider;
        $results = $provider->search('ai', new AssetSearchOptions(types: [MediaAssetType::Video], maxResults: 2));

        $this->assertCount(2, $results);
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --filter=LocalAssetProviderTest`
Expected: FAIL — `Class "App\Domain\Video\Providers\LocalAssetProvider" not found`.

- [ ] **Step 4: Implement `LocalAssetProvider`**

`app/Domain/Video/Providers/LocalAssetProvider.php`:

```php
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
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=LocalAssetProviderTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Create `FakeAssetProvider` and register `AssetServiceProvider`**

`app/Domain/Video/Providers/FakeAssetProvider.php`:

```php
<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\AssetSearchOptions;
use App\Models\MediaAsset;

final class FakeAssetProvider implements AssetProviderInterface
{
    /** @var array<int, MediaAsset> */
    private array $pool = [];

    /**
     * @param  array<int, MediaAsset>  $pool
     */
    public function respondWith(array $pool): static
    {
        $this->pool = $pool;

        return $this;
    }

    /**
     * @return array<int, MediaAsset>
     */
    public function search(string $query, AssetSearchOptions $options): array
    {
        return array_values(array_filter(
            $this->pool,
            fn (MediaAsset $asset): bool => in_array($asset->type, $options->types, true)
                && ! in_array($asset->id, $options->excludeAssetIds, true)
        ));
    }
}
```

`FakeAssetProvider` filters its in-memory pool by type and exclude-list for real
(instead of always returning a fixed list) — this is what lets Task 2's tests
exercise `CollectVideoAssetsService`'s dedup-then-fallback logic deterministically,
without needing a real database-backed tag match.

`app/Providers/AssetServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\Providers\LocalAssetProvider;
use Illuminate\Support\ServiceProvider;

class AssetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AssetProviderInterface::class, LocalAssetProvider::class);
    }
}
```

In `bootstrap/providers.php`, add the import and register it alongside
`TtsServiceProvider`:

```php
<?php

use App\Providers\AppServiceProvider;
use App\Providers\AssetServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\LlmServiceProvider;
use App\Providers\TtsServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    HorizonServiceProvider::class,
    LlmServiceProvider::class,
    TtsServiceProvider::class,
    AssetServiceProvider::class,
];
```

There's no dedicated test for the binding itself — Task 2's service tests exercise
it indirectly by binding `FakeAssetProvider` the same way `FakeTtsProvider` is bound
in `GenerateVoiceoverJobTest`.

- [ ] **Step 7: Run the full test suite and Pint to confirm nothing broke**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, clean.

- [ ] **Step 8: Commit**

```bash
git add app/Domain/Video/AssetSearchOptions.php app/Domain/Video/AssetProviderInterface.php \
  app/Domain/Video/Providers/LocalAssetProvider.php app/Domain/Video/Providers/FakeAssetProvider.php \
  app/Providers/AssetServiceProvider.php bootstrap/providers.php \
  tests/Unit/Domain/Video/LocalAssetProviderTest.php
git commit -m "Add AssetProviderInterface with LocalAssetProvider and FakeAssetProvider"
```

---

### Task 2: `CollectVideoAssetsService`

**Files:**
- Create: `app/Domain/Video/Exceptions/AssetNotFoundException.php`
- Create: `app/Domain/Video/Services/CollectVideoAssetsService.php`
- Test: `tests/Feature/Domain/Video/CollectVideoAssetsServiceTest.php`

**Interfaces:**
- Consumes: `AssetProviderInterface::search()`, `AssetSearchOptions`, `FakeAssetProvider` (Task 1); `Video::$scenes` (ordered `HasMany` of `VideoScene`, from Phase 1); `VideoScene::$visual_query`, `$asset_id`, `$type` (`App\Models\Enums\VideoSceneType`); `MediaAsset::$id`, `$type`.
- Produces: `CollectVideoAssetsService::__construct(AssetProviderInterface $assetProvider)`, `CollectVideoAssetsService::collect(Video $video): array<int, int>` (scene_id => media_asset_id), throws `App\Domain\Video\Exceptions\AssetNotFoundException` — consumed by Task 3's `CollectVideoAssetsJob`.

- [ ] **Step 1: Create `AssetNotFoundException`**

`app/Domain/Video/Exceptions/AssetNotFoundException.php`:

```php
<?php

namespace App\Domain\Video\Exceptions;

use RuntimeException;

final class AssetNotFoundException extends RuntimeException {}
```

No test for this step — it's a plain marker exception, same as `SceneGenerationFailedException`.

- [ ] **Step 2: Write the failing tests for `CollectVideoAssetsService`**

`tests/Feature/Domain/Video/CollectVideoAssetsServiceTest.php`:

```php
<?php

namespace Tests\Feature\Domain\Video;

use App\Domain\Video\Exceptions\AssetNotFoundException;
use App\Domain\Video\Providers\FakeAssetProvider;
use App\Domain\Video\Services\CollectVideoAssetsService;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoSceneType;
use App\Models\MediaAsset;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectVideoAssetsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_assigns_an_asset_only_to_scenes_that_need_one(): void
    {
        $video = Video::factory()->create();

        $needsAsset = VideoScene::factory()->create([
            'video_id' => $video->id,
            'order' => 0,
            'type' => VideoSceneType::Broll,
            'visual_query' => 'ai server',
            'asset_id' => null,
        ]);
        VideoScene::factory()->create([
            'video_id' => $video->id,
            'order' => 1,
            'type' => VideoSceneType::Text,
            'visual_query' => null,
            'asset_id' => null,
        ]);
        $alreadyAssigned = MediaAsset::factory()->create(['type' => MediaAssetType::Video]);
        VideoScene::factory()->create([
            'video_id' => $video->id,
            'order' => 2,
            'type' => VideoSceneType::Broll,
            'visual_query' => 'server room',
            'asset_id' => $alreadyAssigned->id,
        ]);

        $match = MediaAsset::factory()->create(['type' => MediaAssetType::Video]);
        $provider = (new FakeAssetProvider)->respondWith([$match]);

        $service = new CollectVideoAssetsService($provider);
        $assignments = $service->collect($video->fresh(['scenes']));

        $this->assertSame([$needsAsset->id => $match->id], $assignments);
    }

    public function test_it_maps_screen_recording_scenes_to_screen_recording_assets_only(): void
    {
        $video = Video::factory()->create();
        $scene = VideoScene::factory()->create([
            'video_id' => $video->id,
            'order' => 0,
            'type' => VideoSceneType::ScreenRecording,
            'visual_query' => 'ide demo',
            'asset_id' => null,
        ]);

        $wrongType = MediaAsset::factory()->create(['type' => MediaAssetType::Video]);
        $rightType = MediaAsset::factory()->create(['type' => MediaAssetType::ScreenRecording]);
        $provider = (new FakeAssetProvider)->respondWith([$wrongType, $rightType]);

        $service = new CollectVideoAssetsService($provider);
        $assignments = $service->collect($video->fresh(['scenes']));

        $this->assertSame([$scene->id => $rightType->id], $assignments);
    }

    public function test_it_deduplicates_assets_across_scenes_but_falls_back_to_reuse_when_the_library_is_small(): void
    {
        $video = Video::factory()->create();

        $scene1 = VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 0, 'type' => VideoSceneType::Broll,
            'visual_query' => 'a', 'asset_id' => null,
        ]);
        $scene2 = VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 1, 'type' => VideoSceneType::Broll,
            'visual_query' => 'b', 'asset_id' => null,
        ]);
        $scene3 = VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 2, 'type' => VideoSceneType::Broll,
            'visual_query' => 'c', 'asset_id' => null,
        ]);

        $assetA = MediaAsset::factory()->create(['type' => MediaAssetType::Video]);
        $assetB = MediaAsset::factory()->create(['type' => MediaAssetType::Video]);
        $provider = (new FakeAssetProvider)->respondWith([$assetA, $assetB]);

        $service = new CollectVideoAssetsService($provider);
        $assignments = $service->collect($video->fresh(['scenes']));

        $this->assertSame([
            $scene1->id => $assetA->id,
            $scene2->id => $assetB->id,
            $scene3->id => $assetA->id,
        ], $assignments);
    }

    public function test_it_throws_when_no_asset_matches_and_nothing_was_used_yet(): void
    {
        $video = Video::factory()->create();
        VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 0, 'type' => VideoSceneType::Broll,
            'visual_query' => 'anything', 'asset_id' => null,
        ]);

        $service = new CollectVideoAssetsService(new FakeAssetProvider);

        $this->expectException(AssetNotFoundException::class);

        $service->collect($video->fresh(['scenes']));
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --filter=CollectVideoAssetsServiceTest`
Expected: FAIL — `Class "App\Domain\Video\Services\CollectVideoAssetsService" not found`.

- [ ] **Step 4: Implement `CollectVideoAssetsService`**

`app/Domain/Video/Services/CollectVideoAssetsService.php`:

```php
<?php

namespace App\Domain\Video\Services;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\AssetSearchOptions;
use App\Domain\Video\Exceptions\AssetNotFoundException;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoSceneType;
use App\Models\Video;

final class CollectVideoAssetsService
{
    public function __construct(private readonly AssetProviderInterface $assetProvider) {}

    /**
     * @return array<int, int> scene_id => media_asset_id
     */
    public function collect(Video $video): array
    {
        $assignments = [];
        $usedAssetIds = [];

        foreach ($video->scenes as $scene) {
            if ($scene->visual_query === null || $scene->asset_id !== null) {
                continue;
            }

            $types = $this->typesFor($scene->type);

            $results = $this->assetProvider->search(
                $scene->visual_query,
                new AssetSearchOptions(types: $types, excludeAssetIds: $usedAssetIds)
            );

            if ($results === [] && $usedAssetIds !== []) {
                $results = $this->assetProvider->search(
                    $scene->visual_query,
                    new AssetSearchOptions(types: $types)
                );
            }

            if ($results === []) {
                throw new AssetNotFoundException(
                    "No local asset found for scene #{$scene->id} (visual_query: \"{$scene->visual_query}\")."
                );
            }

            $assignments[$scene->id] = $results[0]->id;
            $usedAssetIds[] = $results[0]->id;
        }

        return $assignments;
    }

    /**
     * @return array<int, MediaAssetType>
     */
    private function typesFor(VideoSceneType $type): array
    {
        return match ($type) {
            VideoSceneType::ScreenRecording => [MediaAssetType::ScreenRecording],
            VideoSceneType::Screenshot, VideoSceneType::Image => [MediaAssetType::Image],
            default => [MediaAssetType::Video, MediaAssetType::Image],
        };
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=CollectVideoAssetsServiceTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Run the full test suite and Pint**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, clean.

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Video/Exceptions/AssetNotFoundException.php \
  app/Domain/Video/Services/CollectVideoAssetsService.php \
  tests/Feature/Domain/Video/CollectVideoAssetsServiceTest.php
git commit -m "Add CollectVideoAssetsService"
```

---

### Task 3: `CollectVideoAssetsJob`

**Files:**
- Create: `app/Jobs/CollectVideoAssetsJob.php`
- Test: `tests/Feature/Jobs/CollectVideoAssetsJobTest.php`

**Interfaces:**
- Consumes: `CollectVideoAssetsService::collect(Video $video): array<int, int>`, throws `AssetNotFoundException` (Task 2); `Video::$status`, `VideoStatus::VoiceGenerated`/`VideoStatus::AssetsReady` (Phase 1); `VideoScene::whereKey()`.
- Produces: `CollectVideoAssetsJob::__construct(int $videoId)`, dispatched as `CollectVideoAssetsJob::dispatch($record->id)` — consumed by Task 4's Filament action.

- [ ] **Step 1: Write the failing job tests**

`tests/Feature/Jobs/CollectVideoAssetsJobTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\Providers\FakeAssetProvider;
use App\Jobs\CollectVideoAssetsJob;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoSceneType;
use App\Models\Enums\VideoStatus;
use App\Models\MediaAsset;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectVideoAssetsJobTest extends TestCase
{
    use RefreshDatabase;

    private function bindFakeAssets(array $pool): void
    {
        $this->app->bind(AssetProviderInterface::class, fn () => (new FakeAssetProvider)->respondWith($pool));
    }

    public function test_it_assigns_assets_and_marks_the_video_assets_ready(): void
    {
        $video = Video::factory()->create(['status' => VideoStatus::VoiceGenerated]);
        $scene = VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 0, 'type' => VideoSceneType::Broll,
            'visual_query' => 'ai server', 'asset_id' => null,
        ]);

        $match = MediaAsset::factory()->create(['type' => MediaAssetType::Video]);
        $this->bindFakeAssets([$match]);

        app()->call([new CollectVideoAssetsJob($video->id), 'handle']);

        $this->assertSame($match->id, $scene->fresh()->asset_id);
        $this->assertSame(VideoStatus::AssetsReady, $video->fresh()->status);
    }

    public function test_it_is_a_no_op_when_the_video_status_is_not_voice_generated(): void
    {
        $video = Video::factory()->create(['status' => VideoStatus::ScriptGenerated]);
        VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 0, 'type' => VideoSceneType::Broll,
            'visual_query' => 'ai server', 'asset_id' => null,
        ]);
        $this->bindFakeAssets([MediaAsset::factory()->create(['type' => MediaAssetType::Video])]);

        app()->call([new CollectVideoAssetsJob($video->id), 'handle']);

        $this->assertSame(VideoStatus::ScriptGenerated, $video->fresh()->status);
    }

    public function test_it_only_fills_scenes_still_missing_an_asset_on_a_repeat_run(): void
    {
        $video = Video::factory()->create(['status' => VideoStatus::VoiceGenerated]);
        $preAssigned = MediaAsset::factory()->create(['type' => MediaAssetType::Video]);
        $sceneAlreadyDone = VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 0, 'type' => VideoSceneType::Broll,
            'visual_query' => 'a', 'asset_id' => $preAssigned->id,
        ]);
        $sceneNeedsOne = VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 1, 'type' => VideoSceneType::Broll,
            'visual_query' => 'b', 'asset_id' => null,
        ]);

        $match = MediaAsset::factory()->create(['type' => MediaAssetType::Video]);
        $this->bindFakeAssets([$match]);

        app()->call([new CollectVideoAssetsJob($video->id), 'handle']);

        $this->assertSame($preAssigned->id, $sceneAlreadyDone->fresh()->asset_id);
        $this->assertSame($match->id, $sceneNeedsOne->fresh()->asset_id);
        $this->assertSame(VideoStatus::AssetsReady, $video->fresh()->status);
    }

    public function test_a_video_with_no_scenes_needing_assets_still_becomes_assets_ready(): void
    {
        $video = Video::factory()->create(['status' => VideoStatus::VoiceGenerated]);
        VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 0, 'type' => VideoSceneType::Text,
            'visual_query' => null, 'asset_id' => null,
        ]);
        $this->bindFakeAssets([]);

        app()->call([new CollectVideoAssetsJob($video->id), 'handle']);

        $this->assertSame(VideoStatus::AssetsReady, $video->fresh()->status);
    }

    public function test_it_throws_and_leaves_the_video_unchanged_when_no_asset_matches(): void
    {
        $video = Video::factory()->create(['status' => VideoStatus::VoiceGenerated]);
        VideoScene::factory()->create([
            'video_id' => $video->id, 'order' => 0, 'type' => VideoSceneType::Broll,
            'visual_query' => 'anything', 'asset_id' => null,
        ]);
        $this->bindFakeAssets([]);

        $this->expectException(\App\Domain\Video\Exceptions\AssetNotFoundException::class);

        app()->call([new CollectVideoAssetsJob($video->id), 'handle']);

        $this->assertSame(VideoStatus::VoiceGenerated, $video->fresh()->status);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=CollectVideoAssetsJobTest`
Expected: FAIL — `Class "App\Jobs\CollectVideoAssetsJob" not found`.

- [ ] **Step 3: Implement `CollectVideoAssetsJob`**

`app/Jobs/CollectVideoAssetsJob.php`:

```php
<?php

namespace App\Jobs;

use App\Domain\Video\Services\CollectVideoAssetsService;
use App\Models\Enums\VideoStatus;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CollectVideoAssetsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public int $uniqueFor = 200;

    public function __construct(public readonly int $videoId) {}

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(CollectVideoAssetsService $service): void
    {
        $video = Video::with('scenes')->findOrFail($this->videoId);

        if ($video->status !== VideoStatus::VoiceGenerated) {
            return;
        }

        $assignments = $service->collect($video);

        DB::transaction(function () use ($video, $assignments) {
            foreach ($assignments as $sceneId => $assetId) {
                VideoScene::whereKey($sceneId)->update(['asset_id' => $assetId]);
            }

            $video->update(['status' => VideoStatus::AssetsReady]);
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('video')->error('Asset collection failed permanently.', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=CollectVideoAssetsJobTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Run the full test suite and Pint**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, clean.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/CollectVideoAssetsJob.php tests/Feature/Jobs/CollectVideoAssetsJobTest.php
git commit -m "Add CollectVideoAssetsJob"
```

---

### Task 4: Filament — "Collect Assets" row action on `VideosTable`

**Files:**
- Modify: `app/Filament/Resources/Videos/Tables/VideosTable.php`
- Test: `tests/Feature/Filament/VideoCollectAssetsActionTest.php`

**Interfaces:**
- Consumes: `CollectVideoAssetsJob::dispatch(int $videoId)` (Task 3), `VideoStatus::VoiceGenerated`.
- Produces: nothing downstream — this is a leaf UI action.

- [ ] **Step 1: Write the failing Filament tests**

`tests/Feature/Filament/VideoCollectAssetsActionTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Jobs\CollectVideoAssetsJob;
use App\Models\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class VideoCollectAssetsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_collect_assets_action_dispatches_the_job_when_voice_generated(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        $video = Video::factory()->create(['status' => VideoStatus::VoiceGenerated]);

        Livewire::test(ListVideos::class)
            ->callTableAction('collectAssets', $video)
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(CollectVideoAssetsJob::class, fn (CollectVideoAssetsJob $job) => $job->videoId === $video->id);
    }

    public function test_collect_assets_action_is_not_visible_before_a_voiceover_exists(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create(['status' => VideoStatus::ScriptGenerated]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('collectAssets', $video);
    }

    public function test_collect_assets_action_is_not_visible_once_assets_are_ready(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create(['status' => VideoStatus::AssetsReady]);

        Livewire::test(ListVideos::class)
            ->assertTableActionHidden('collectAssets', $video);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=VideoCollectAssetsActionTest`
Expected: FAIL — table action `collectAssets` not found.

- [ ] **Step 3: Add the row action to `VideosTable`**

`app/Filament/Resources/Videos/Tables/VideosTable.php`:

```php
<?php

namespace App\Filament\Resources\Videos\Tables;

use App\Jobs\CollectVideoAssetsJob;
use App\Jobs\GenerateVoiceoverJob;
use App\Models\Enums\VideoStatus;
use App\Models\Video;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VideosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contentProject.name')
                    ->searchable(),
                TextColumn::make('contentIdea.title')
                    ->searchable(),
                TextColumn::make('script.id')
                    ->searchable(),
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('duration')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('width')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('height')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('file_path')
                    ->searchable(),
                TextColumn::make('thumbnail_path')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('generateVoiceover')
                    ->label('Generate Voiceover')
                    ->visible(fn (Video $record): bool => $record->status === VideoStatus::ScriptGenerated
                        && ! $record->voiceover()->exists())
                    ->requiresConfirmation()
                    ->action(function (Video $record): void {
                        GenerateVoiceoverJob::dispatch($record->id);

                        Notification::make()->title('Voiceover generation queued')->success()->send();
                    }),
                Action::make('collectAssets')
                    ->label('Collect Assets')
                    ->visible(fn (Video $record): bool => $record->status === VideoStatus::VoiceGenerated)
                    ->requiresConfirmation()
                    ->action(function (Video $record): void {
                        CollectVideoAssetsJob::dispatch($record->id);

                        Notification::make()->title('Asset collection queued')->success()->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=VideoCollectAssetsActionTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Run the full test suite and Pint**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, clean.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/Videos/Tables/VideosTable.php \
  tests/Feature/Filament/VideoCollectAssetsActionTest.php
git commit -m "Filament: Collect Assets row action on Video"
```

---

### Task 5: Filament — editable tags on `MediaAssetForm`

**Files:**
- Modify: `app/Filament/Resources/MediaAssets/Schemas/MediaAssetForm.php`
- Test: `tests/Feature/Filament/MediaAssetTagsFormTest.php`

**Interfaces:**
- Consumes: `App\Models\MediaAsset` (`metadata` array cast, Phase 1).
- Produces: nothing downstream — `LocalAssetProvider` (Task 1) reads `metadata['tags']` straight from the database column regardless of how it was written, so there's no code dependency; this task only makes the local library practically fillable through the admin panel.

- [ ] **Step 1: Write the failing form tests**

`tests/Feature/Filament/MediaAssetTagsFormTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\MediaAssets\Pages\CreateMediaAsset;
use App\Filament\Resources\MediaAssets\Pages\EditMediaAsset;
use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MediaAssetTagsFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_media_asset_saves_tags_into_metadata(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateMediaAsset::class)
            ->fillForm([
                'type' => MediaAssetType::Video->value,
                'provider' => 'local',
                'path' => 'assets/server-room.mp4',
                'mime_type' => 'video/mp4',
                'hash' => str_repeat('a', 64),
                'metadata.tags' => ['ai', 'server', 'room'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $asset = MediaAsset::sole();
        $this->assertSame(['ai', 'server', 'room'], $asset->metadata['tags']);
    }

    public function test_editing_a_media_asset_updates_its_tags(): void
    {
        $this->actingAs(User::factory()->create());

        $asset = MediaAsset::factory()->create(['metadata' => ['tags' => ['old']]]);

        Livewire::test(EditMediaAsset::class, ['record' => $asset->getRouteKey()])
            ->fillForm(['metadata.tags' => ['new', 'tags']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['new', 'tags'], $asset->fresh()->metadata['tags']);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=MediaAssetTagsFormTest`
Expected: FAIL — the `metadata` field is currently `disabled()`, so `metadata.tags` never reaches the database (the create assertion on `$asset->metadata['tags']` fails with an undefined array key, and/or `assertHasNoFormErrors` still passes but the tags are missing).

- [ ] **Step 3: Replace the disabled `metadata` field with a `TagsInput` on `metadata.tags`**

`app/Filament/Resources/MediaAssets/Schemas/MediaAssetForm.php`:

```php
<?php

namespace App\Filament\Resources\MediaAssets\Schemas;

use App\Models\Enums\MediaAssetType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class MediaAssetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->options(MediaAssetType::class)
                    ->required(),
                TextInput::make('provider')
                    ->required()
                    ->default('local'),
                TextInput::make('path')
                    ->required(),
                TextInput::make('mime_type')
                    ->required(),
                TextInput::make('width')
                    ->numeric(),
                TextInput::make('height')
                    ->numeric(),
                TextInput::make('duration')
                    ->numeric(),
                TagsInput::make('metadata.tags')
                    ->label('Tags')
                    ->helperText('Ключові слова для пошуку через LocalAssetProvider (порівнюються з visual_query сцени).')
                    ->separator(','),
                TextInput::make('hash')
                    ->required(),
            ]);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=MediaAssetTagsFormTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the full test suite and Pint**

Run: `php artisan test && vendor/bin/pint --test`
Expected: PASS, clean.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/MediaAssets/Schemas/MediaAssetForm.php \
  tests/Feature/Filament/MediaAssetTagsFormTest.php
git commit -m "Filament: editable tags on MediaAsset form"
```

---

### Task 6: Final verification

**Files:**
- None (verification only).

**Interfaces:**
- Consumes: everything from Tasks 1-5.
- Produces: nothing downstream — this is the final gate for Phase 3c.

- [ ] **Step 1: Run the full verification suite**

```bash
php artisan migrate:fresh --seed --database=pgsql
php artisan test
vendor/bin/pint --test
php artisan route:list > /dev/null
```

Expected: every test from Phase 0-3b and Phase 3c Tasks 1-5 passes, Pint clean,
`route:list` doesn't error, `migrate:fresh --seed` completes cleanly (no new
migrations were added in Phase 3c, so this mainly re-confirms Phase 1's seeder
still works against the unchanged schema).

- [ ] **Step 2: Manually verify the DoD slice**

In Filament: create a few `MediaAsset` rows via `MediaAssetResource` (e.g. type
`video`, tags `["ai", "server", "room"]`; type `screen_recording`, tags `["ide",
"code", "demo"]`) so the local library has something to match against. Take a
`Video` already in `VoiceGenerated` status (from the Phase 3b flow) whose scenes
have non-null `visual_query` values overlapping those tags: "Collect Assets"
appears on the `Video` row → click it → confirm each eligible `VideoScene` (visible
via `VideoSceneResource`'s list) now has `asset_id` populated and `Video.status` is
`AssetsReady`. Re-clicking (the button hides once `status` moves past
`VoiceGenerated`; force a second dispatch via `php artisan tinker` if you want to
confirm idempotency) must not change any already-assigned `asset_id`. Also verify
the failure path once: point a `Video` with a `visual_query` that matches nothing in
the library, dispatch the job, and confirm the `video` log channel records the
`AssetNotFoundException` message after retries are exhausted, with `Video.status`
unchanged.

`ROADMAP.md` is updated separately, outside this plan, once this phase's work is
reviewed as a whole (same as after Phase 3a/3b).
