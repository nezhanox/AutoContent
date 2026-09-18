# Phase 5 — Analytics: design

Джерело вимог: `TechnicalTask.md` розділи 4 (`VideoMetric`, `LlmUsageLog`), 5
(`CollectMetricsService`), 14 (Admin Panel — Dashboard, Analytics), 24 (DoD —
усі 16 пунктів вже закрито в Phase 4; Phase 5 додає аналітику поза цим
списком). Роадмап-контекст: `ROADMAP.md` Phase 5.

## Мета

Побудувати цикл збору метрик публікацій (`VideoMetric`, поки через
`FakeSocialPublisher`) і показати огляд стану пайплайна та вартості LLM в
Filament: головний Dashboard (відео/публікації/помилки/перегляди/найкращий
контент) і окремий звіт по `LlmUsageLog` (вартість/токени по
provider/model/purpose).

## 1. Збір метрик

### 1.1 `SocialPublisherInterface::fetchMetrics()`

Новий метод на вже існуючому інтерфейсі (`app/Domain/Publishing/
SocialPublisherInterface.php`):

```php
interface SocialPublisherInterface
{
    public function publish(Publication $publication): PublishResult;

    public function fetchMetrics(Publication $publication): VideoMetricsResult;
}
```

### 1.2 `VideoMetricsResult` DTO

`app/Domain/Publishing/VideoMetricsResult.php` — readonly, за зразком
`PublishResult`:

```php
final class VideoMetricsResult
{
    public function __construct(
        public readonly int $views,
        public readonly int $likes,
        public readonly int $comments,
        public readonly int $shares,
        public readonly ?int $saves = null,
        public readonly ?int $watchTime = null,
        public readonly ?float $completionRate = null,
        public readonly ?int $followersGained = null,
        public readonly array $metadata = [],
    ) {}
}
```

Поля 1:1 відповідають fillable-полям `VideoMetric` (мінус `publication_id` і
`measured_at`, які виставляє job).

### 1.3 `FakeSocialPublisher::fetchMetrics()`

Монотонне псевдо-зростання: бере останній `VideoMetric` цієї `Publication`
(`->videoMetrics()->latest('measured_at')->first()`), і повертає
попередні значення + невеликий випадковий приріст (`views`/`likes`/
`comments`/`shares` ніколи не спадають). Якщо попередніх записів нема —
стартові значення з невеликого випадкового діапазону. Мета — щоб
"Best videos"/"Top topics" на Dashboard виглядали правдоподібно на
демо-даних, а не як шум.

### 1.4 `CollectVideoMetricsJob`

`app/Jobs/CollectVideoMetricsJob.php`, той самий каркас, що й
`PublishVideoJob`: `ShouldQueue`, `NotifiesOnPermanentFailure`, `tries=3`,
`backoff(): [15, 60, 120]`, `timeout=60`. **Без** `ShouldBeUnique` — це
time-series, кожен тик легітимно додає новий рядок, на відміну від
одноразового `publish()`.

```php
public function __construct(public readonly int $publicationId) {}

public function handle(SocialPublisherInterface $publisher): void
{
    $publication = Publication::findOrFail($this->publicationId);

    if ($publication->status !== PublicationStatus::Published) {
        return;
    }

    $result = $publisher->fetchMetrics($publication);

    VideoMetric::create([
        'publication_id' => $publication->id,
        'views' => $result->views,
        'likes' => $result->likes,
        'comments' => $result->comments,
        'shares' => $result->shares,
        'saves' => $result->saves,
        'watch_time' => $result->watchTime,
        'completion_rate' => $result->completionRate,
        'followers_gained' => $result->followersGained,
        'metadata' => $result->metadata,
        'measured_at' => now(),
    ]);
}
```

`failed()` викликає `notifyPermanentFailure('analytics', ...)` за тим самим
патерном, що й інші 9 jobs — жодного статус-поля на `Publication`/`Video` тут
скидати не треба (метрики не блокують і не змінюють pipeline-стан).

### 1.5 `metrics:collect` команда + Scheduler

`app/Console/Commands/CollectMetricsCommand.php`, за зразком
`DispatchDuePublicationsCommand`:

```php
protected $signature = 'metrics:collect';

public function handle(): int
{
    $published = Publication::where('status', PublicationStatus::Published)->get();

    foreach ($published as $publication) {
        CollectVideoMetricsJob::dispatch($publication->id);
    }

    $this->info("Dispatched {$published->count()} metrics collection job(s).");

    return self::SUCCESS;
}
```

Без обмеження за віком публікації — метрики збираються безстроково, поки
`status === Published`. Реєстрація в `bootstrap/app.php`:

```php
$schedule->command('metrics:collect')->hourly()->withoutOverlapping();
```

## 2. `VideoMetricResource` → view-only

- `VideoMetricResource::canCreate(): false` — той самий патерн, що
  `LlmUsageLogResource`/`ScriptResource` ("append-only, пишеться лише
  pipeline-jobʼом").
- `VideoMetricsTable`: прибрати `EditAction`, лишити перегляд (без окремого
  view-page — таблиця вже показує всі поля) і `DeleteBulkAction` (ручне
  чищення сміття/дублів лишається можливим).
- `getPages()` — лишити тільки `index` (`CreateVideoMetric`/`EditVideoMetric`
  Page-класи і відповідні маршрути видалити).

## 3. Dashboard widgets

Спільна точка перевикористання — метод на моделі `VideoMetric`:

```php
// app/Models/VideoMetric.php
public static function latestPerPublication(): Builder
{
    return static::query()
        ->selectRaw('DISTINCT ON (publication_id) *')
        ->orderBy('publication_id')
        ->orderByDesc('measured_at');
}
```

(Postgres-специфічний `DISTINCT ON` — проєкт вже цілиться виключно в Postgres,
той самий підхід, що дав `text` → `json` міграцію в Phase 4.)

### 3.1 `PipelineStatsWidget` (`app/Filament/Widgets/PipelineStatsWidget.php`)

`Filament\Widgets\StatsOverviewWidget`, 6 карток:

| Картка | Запит |
|---|---|
| Videos generated today | `Video::where('status', VideoStatus::Rendered)->whereDate('updated_at', today())->count()` |
| Videos published | `Publication::where('status', PublicationStatus::Published)->count()` (кумулятивно) |
| Failed jobs (today) | `DatabaseNotification::where('type', PipelineJobFailedNotification::class)->whereDate('created_at', today())->count()` |
| Views | `VideoMetric::latestPerPublication()` обгорнуто підзапитом → `sum('views')` |
| Likes | те саме, `sum('likes')` |
| Comments | те саме, `sum('comments')` |

### 3.2 `BestVideosWidget` (`app/Filament/Widgets/BestVideosWidget.php`)

Table widget, топ-5 `Publication` за `views` з `VideoMetric::latestPerPublication()`
(join на `publications`/`videos`), колонки: video title, platform
(`socialAccount.platform`), views, likes, comments.

### 3.3 `TopTopicsWidget` (`app/Filament/Widgets/TopTopicsWidget.php`)

Table widget, групування того самого зрізу по
`Publication → Video → ContentIdea.topic`, `sum(views)` як `total_views`, топ-5
тем за спаданням.

Усі три реєструються в `AdminPanelProvider::widgets()` поряд з
`AccountWidget`/`FilamentInfoWidget`.

## 4. LLM Usage Report

`app/Filament/Pages/LlmUsageReport.php` — custom Filament Page (не Resource,
бо дані — агрегація, не 1:1 Eloquent-запис), `navigationGroup = 'Analytics'`.
Реалізує `Filament\Tables\Contracts\HasTable` + `Filament\Tables\Concerns\
InteractsWithTable`. Query:

```php
LlmUsageLog::query()
    ->selectRaw('provider, model, purpose,
        COUNT(*) as calls,
        SUM(prompt_tokens) as prompt_tokens,
        SUM(completion_tokens) as completion_tokens,
        SUM(prompt_tokens + completion_tokens) as total_tokens,
        SUM(cost) as total_cost,
        SUM(CASE WHEN status = \'success\' THEN 1 ELSE 0 END) as success_count')
    ->groupBy('provider', 'model', 'purpose')
    ->orderByDesc('total_cost')
```

Колонки: provider, model, purpose, calls, prompt_tokens, completion_tokens,
total_tokens, total_cost, success rate (`success_count / calls`, форматовано
як %). Без date-range фільтра в MVP — весь наявний `LlmUsageLog`.

## 5. Тестування

- `CollectVideoMetricsJobTest`: no-op на не-`Published` публікації; створює
  `VideoMetric` з правильними полями/`measured_at`; `NotifiesOnPermanentFailure`
  спрацьовує після вичерпання retries.
- `CollectMetricsCommandTest`: диспатчить job лише для `status=Published`
  (`Queue::fake()`, перевірка кількості й аргументів).
- `FakeSocialPublisherMetricsTest`: послідовні виклики `fetchMetrics()` дають
  монотонне зростання views/likes/comments/shares.
- `PipelineStatsWidgetTest` / `BestVideosWidgetTest` / `TopTopicsWidgetTest`:
  Livewire-тести (`Livewire::test(...)->assertSee(...)`) на підготовлених
  фікстурах `VideoMetric`/`Publication`/`ContentIdea`.
- `LlmUsageReportTest`: групування/суми на підготовлених `LlmUsageLog` записах.
- `VideoMetricResource`: `create`/`edit` маршрути недоступні (за зразком
  наявного покриття `LlmUsageLogResource`/`ScriptResource`, якщо таке вже є —
  інакше додається тим самим стилем).

## 6. Свідомо поза скоупом Phase 5

- Відсутня таблиця `failed_jobs` (немає `queue:failed-table`-міграції, хоча
  `config/queue.php`'s `failed.driver` — `database-uuids`) — інфраструктурний
  гап черги, не Analytics-домену. "Failed jobs" картка Dashboard навмисно
  спирається на вже робочий і протестований канал `notifications`
  (`PipelineJobFailedNotification`, Phase 4), тож це не блокер зараз, але
  вартий окремого фіксу в Phase 6+.
- Реальні social API для views/likes/comments (TikTok/YouTube/Instagram/X) —
  Phase 6+; `fetchMetrics()` зараз реалізовано лише в `FakeSocialPublisher`.
- Date-range фільтр на LLM Usage Report — додається пізніше, якщо знадобиться.
