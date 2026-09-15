# Phase 3c — Assets: design spec

Джерело: `TechnicalTask.md` (розділи 4, 7, 8, 12, 17-19), `ROADMAP.md` (Phase 3,
частина "`MediaAsset` (локальні/stock assets), `AssetProviderInterface`").

## Мета

Від `Video` з готовою озвучкою (Phase 3b) до `VideoScene.asset_id`, заповненого для
кожної сцени, якій потрібен візуальний матеріал — через локальну бібліотеку
`MediaAsset`, без прив'язки до конкретного stock-провайдера. Третя з п'яти
запланованих під-фаз Phase 3 (3a Сцени → 3b Voiceover → **3c Assets** → 3d Subtitles
→ 3e Rendering+QualityCheck — Assets переїхав з початкового 3b у власну під-фазу під
час брейнштормінгу 3b, оскільки Assets і Voiceover виявились незалежними
підсистемами порівнянного з 3a масштабу).

## Скоуп

Входить:

* `app/Domain/Video/AssetProviderInterface.php` + `AssetSearchOptions` DTO (розділ 12
  ТЗ).
* `app/Domain/Video/Providers/LocalAssetProvider.php` — єдина "реальна" реалізація
  на MVP: шукає серед уже існуючих рядків `MediaAsset` за збігом ключових слів
  `visual_query` з `metadata.tags` (розділи 12, 23 ТЗ — "MVP можна почати з
  локальних assets", stock-провайдери — Phase 6+).
  `app/Domain/Video/Providers/FakeAssetProvider.php` — тестовий двійник.
* `app/Providers/AssetServiceProvider.php` — біндинг `AssetProviderInterface` напряму
  на `LocalAssetProvider` (без Manager-шару, той самий підхід, що
  `TtsServiceProvider`).
* `app/Domain/Video/Exceptions/AssetNotFoundException.php`.
* `app/Domain/Video/Services/CollectVideoAssetsService.php`.
* `app/Jobs/CollectVideoAssetsJob.php` — idempotent, timeout/retry.
* Filament: row action "Collect Assets" на `VideosTable`; `MediaAssetForm` отримує
  редаговане поле тегів (`metadata.tags`) — зараз `metadata` це `disabled()`
  `TextInput` (Phase 1 scaffold), тобто адмін не може прописати теги через UI
  взагалі; без цього фіксу локальну бібліотеку неможливо наповнити для реального
  тестування `LocalAssetProvider`.
* Feature/Unit-тести: провайдер, сервіс, job, Filament-дія, форма `MediaAsset`.

Не входить (свідомо відкладено):

* Реальні stock-провайдери (Pexels/Pixabay/Unsplash/AI generators) — Phase 6+
  (розділ 12 ТЗ, ROADMAP "Post-MVP").
* File upload widget + автообчислення `hash`/`width`/`height`/`duration` для
  `MediaAsset` — адмін і далі вручну кладе файл на диск і прописує `path`/`hash`
  (той самий рівень ручного введення, що вже є в Phase 1 scaffold; тільки
  `metadata.tags` стає редагованим у цій фазі).
* Whisper/субтитри — Phase 3d.
* `FfmpegVideoRenderer`/рендеринг/`QualityCheckJob` — Phase 3e.
* Перетворення `VideoResource`/`VideoSceneResource` на view-only — й далі відкладено
  до фінальної під-фази Phase 3 (за аналогією зі `ScriptResource` у Phase 2).

## Рішення (там, де ТЗ не фіксує деталь явно)

### `AssetProviderInterface` і `AssetSearchOptions`

```php
interface AssetProviderInterface
{
    /**
     * @return array<int, MediaAsset>
     */
    public function search(string $query, AssetSearchOptions $options): array;
}
```

```php
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

Повертає вже персистентні `MediaAsset`-моделі, а не окремий DTO-"кандидат". На MVP
єдиний провайдер (`LocalAssetProvider`) завжди працює з уже існуючими рядками БД —
винаходити абстракцію "непересистований кандидат, що вимагає завантаження" зараз
немає сенсу (YAGNI): коли Phase 6+ додасть реального stock-провайдера, саме той
провайдер отримає відповідальність завантажити обраний асет і створити `MediaAsset`
перед поверненням з `search()` — контракт інтерфейсу (`array<MediaAsset>`) для цього
міняти не потрібно.

### `LocalAssetProvider` — матчинг за тегами

```php
final class LocalAssetProvider implements AssetProviderInterface
{
    public function search(string $query, AssetSearchOptions $options): array
    {
        $queryWords = $this->words($query);

        return MediaAsset::query()
            ->whereIn('type', $options->types)
            ->when($options->excludeAssetIds !== [], fn ($q) => $q->whereNotIn('id', $options->excludeAssetIds))
            ->get()
            ->map(fn (MediaAsset $asset) => [$asset, $this->score($queryWords, $asset)])
            ->filter(fn (array $pair) => $pair[1] > 0)
            ->sortByDesc(fn (array $pair) => $pair[1])
            ->take($options->maxResults)
            ->pluck(0)
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function words(string $text): array
    {
        return array_values(array_filter(array_unique(array_map(
            static fn (string $w): string => mb_strtolower($w),
            preg_split('/\s+/u', trim($text)) ?: []
        ))));
    }

    /** @param  array<int, string>  $queryWords */
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

Пошук — по всій таблиці `media_assets` (наявний обсяг бібліотеки на MVP не вимагає
DB-side full-text search); asset без жодного спільного тега з запитом (score = 0) не
повертається взагалі — краще явна відсутність результату (і, відповідно,
`AssetNotFoundException` вище по стеку), ніж випадковий асет не в тему.

`FakeAssetProvider` (`respondWith`-стиль, той самий підхід, що `FakeTtsProvider`):

```php
final class FakeAssetProvider implements AssetProviderInterface
{
    /** @var array<int, MediaAsset> */
    private array $results = [];

    /** @param  array<int, MediaAsset>  $results */
    public function respondWith(array $results): static
    {
        $this->results = $results;

        return $this;
    }

    public function search(string $query, AssetSearchOptions $options): array
    {
        return $this->results;
    }
}
```

### `CollectVideoAssetsService` — вибір типу asset за типом сцени, dedup, фолбек

```php
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

    /** @return array<int, MediaAssetType> */
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

Сигнатура — той самий підхід, що `GenerateScenesService::generate()`/
`GenerateVoiceoverService::generate()`: сервіс не має побічних ефектів у БД,
повертає дані для персистенції в Job (розділ 5 ТЗ — сервіс не знає про queue/job).

`typesFor()`: `screen_recording` сцена шукає точний відповідник
`MediaAssetType::ScreenRecording`; `image`/`screenshot` шукають `Image` (у
`MediaAssetType` немає окремого "screenshot" — скріншоти зберігаються як `Image`);
решта типів сцен (`hook`/`broll`/`generated_video`/`cta`/`transition`) шукають і
`Video`, і `Image` — без жорсткої матриці для кожного типу, TЗ її не фіксує.

Dedup: перший пошук виключає вже призначені в цьому відео asset'и
(`excludeAssetIds`), щоб не показувати один і той самий B-roll у кожній сцені.
Якщо після виключення нічого не знайдено, а бібліотека вже щось віддавала раніше
(`$usedAssetIds !== []`) — другий пошук без виключення: маленька бібліотека не
повинна валити генерацію відео через дедуплікацію, повтор asset'у в межах одного
відео прийнятний для MVP. Якщо і другий пошук порожній — `AssetNotFoundException`
(`app/Domain/Video/Exceptions/AssetNotFoundException.php`, `final class ... extends
RuntimeException {}`, той самий підхід, що `SceneGenerationFailedException`).

Сцени без `visual_query` (напр. `text`) або з уже заповненим `asset_id` (повторний
запуск) пропускаються — саме це робить `collect()` idempotent на рівні даних, не
лише на рівні job-guard'а.

### `CollectVideoAssetsJob` — guard і транзакція

```php
class CollectVideoAssetsJob implements ShouldBeUnique, ShouldQueue
{
    public int $timeout = 120;
    public int $tries = 3;
    public function uniqueId(): string { return (string) $this->videoId; }
    public int $uniqueFor = 200;
    public function backoff(): array { return [10, 30, 60]; }
}
```

Ті самі значення retry/backoff, що `GenerateScenesJob`/`GenerateVoiceoverJob`;
коротший `timeout` (120с) — на відміну від LLM/TTS-викликів тут немає зовнішнього
HTTP API, лише DB-запити.

`handle()`:

1. `Video::with('scenes')->findOrFail($this->videoId)`.
2. Guard: якщо `status !== VideoStatus::VoiceGenerated` — вихід без дій. На відміну
   від 3a/3b тут не потрібен другий guard "вже існує" — це не окрема сутність з
   унікальним індексом, а мутація `VideoScene.asset_id`/`Video.status`; сам статус
   `VoiceGenerated` вже виключає повторний запуск після успішного завершення
   (перехід у `AssetsReady`), а idempotency на рівні даних (`collect()` пропускає
   вже заповнені сцени) покриває проміжний ре-запуск після часткового падіння.
3. `CollectVideoAssetsService::collect($video)` — поза транзакцією (може кинути
   `AssetNotFoundException`, у такому разі DB не чіпається).
4. `DB::transaction()`: для кожної пари `scene_id => asset_id` —
   `VideoScene::whereKey($sceneId)->update(['asset_id' => $assetId])`, потім
   `$video->update(['status' => VideoStatus::AssetsReady])` — атомарно разом (той
   самий підхід, що фінальний фікс `GenerateScenesJob`/`GenerateVoiceoverJob`).

Якщо у відео взагалі немає сцен з `visual_query !== null` (усі сцени — `text`),
`collect()` повертає порожній масив — транзакція все одно виконує перехід у
`AssetsReady` (нема чого призначати, але й нема причини блокувати пайплайн).

`failed(Throwable $exception)`: `Log::channel('video')->error(...)` — без зміни
DB-стану (той самий підхід, що `GenerateScenesJob`/`GenerateVoiceoverJob`; відомий,
свідомо не закритий тут гап з ROADMAP carry-over — permanent failure не дає
користувачу видимого сигналу окрім логу).

### Filament

Row action "Collect Assets" на `app/Filament/Resources/Videos/Tables/
VideosTable.php`:

```php
Action::make('collectAssets')
    ->label('Collect Assets')
    ->visible(fn (Video $record): bool => $record->status === VideoStatus::VoiceGenerated)
    ->requiresConfirmation()
    ->action(function (Video $record): void {
        CollectVideoAssetsJob::dispatch($record->id);
        Notification::make()->title('Asset collection queued')->success()->send();
    }),
```

`MediaAssetForm` (`app/Filament/Resources/MediaAssets/Schemas/MediaAssetForm.php`):
заміна `TextInput::make('metadata')->disabled()` на

```php
TagsInput::make('metadata.tags')
    ->label('Tags')
    ->helperText('Ключові слова для пошуку через LocalAssetProvider (порівнюються з visual_query сцени).')
    ->separator(','),
```

Filament резолвить вкладені шляхи стану (`metadata.tags`) напряму в JSON-масив
`metadata`-колонки — стандартна ідіома для `array`-cast полів, без окремих
`mutateFormDataBeforeSave`/`afterStateHydrated`-хуків. `TextInput::make('provider')`
отримує `->default('local')` (усі asset'и, що вводяться вручну через цю форму, за
задумом `provider = 'local'`; поле лишається редагованим текстовим, не
блокується — той самий рівень свободи, що `provider` на `Script`/`Voiceover`).

### Тести

* Unit: `LocalAssetProviderTest` — матч за перетином тегів, фільтр за `types`,
  фільтр за `excludeAssetIds`, найкращий score першим, порожній масив коли нема
  жодного перетину.
* Feature: `CollectVideoAssetsServiceTest` — призначає asset'и лише сценам з
  `visual_query !== null` і `asset_id === null`, dedup-фолбек (виключення →
  порожньо → повтор без виключення), `AssetNotFoundException` коли й другий пошук
  порожній, `FakeAssetProvider`.
* Feature: `CollectVideoAssetsJobTest` — happy path (усі потрібні сцени отримують
  `asset_id`, `Video.status` → `AssetsReady`), no-op якщо `status !==
  VoiceGenerated`, ідемпотентний повторний запуск заповнює лише відсутні
  `asset_id`, відео без жодної сцени з `visual_query` все одно переходить у
  `AssetsReady`.
* Feature: Filament-тест на видимість/dispatch дії `collectAssets`.
* Feature: `MediaAssetResource`-форма — теги, введені через `TagsInput`,
  зберігаються в `metadata.tags` після create/edit.

## Acceptance criteria (для 3c, частина ширшого DoD Phase 3 — просуває пункт 6
розділу 24 ТЗ, Video Scenes, до стану, придатного для рендерингу в 3e)

* Бібліотека `MediaAsset` наповнюється через Filament з тегами (`metadata.tags`).
* Натискання "Collect Assets" на `Video` з `VoiceGenerated` заповнює `asset_id`
  усіх сцен, яким він потрібен, і переводить `Video.status` у `AssetsReady`.
* Повторний dispatch того самого `Video` не змінює вже призначені `asset_id` і не
  ламається (idempotent).
* Відсутність підходящого asset'а в бібліотеці дає видиму помилку в логах (канал
  `video`) замість мовчазного часткового результату.
* `php artisan test`, `vendor/bin/pint --test`, `php artisan route:list`,
  `php artisan migrate:fresh --seed` проходять чисто.
