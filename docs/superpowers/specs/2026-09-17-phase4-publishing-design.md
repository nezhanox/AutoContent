# Phase 4 — Publishing: design

Джерело вимог: `TechnicalTask.md` розділи 15 (Social publishing), 16 (Publishing
Scheduler), 24 (DoD пп.11-16), 27 (критична вимога: caption/hashtags per platform,
не прив'язувати ContentIdea/Script до платформи). Роадмап-контекст: `ROADMAP.md`
Phase 4.

## Мета

Від готового `Video` до опублікованої (fake) публікації: адмін створює
`Publication` (Video × SocialAccount), генерує caption/hashtags через LLM,
призначає `scheduled_at`, і Laravel Scheduler автоматично публікує її через
`FakeSocialPublisher`, ідемпотентно.

## 1. Дані

### 1.1 Публікація caption/hashtags

Нова міграція додає на `publications`:

* `caption` — `text`, nullable
* `hashtags` — `jsonb`, default `'[]'`, cast `array`

Обґрунтування: §27 ТЗ вимагає, щоб один Script/Video міг мати різні
Caption/Hashtags для різних платформ. Оскільки один `Video` може мати декілька
незалежних `Publication` (по одній на кожен `SocialAccount`/платформу), а
Caption/Hashtags специфічні саме для комбінації Video×Platform — вони належать
`Publication`, не `Video` і не `Script`.

### 1.2 Database notifications

`php artisan notifications:table` — нова таблиця `notifications`. `User` вже має
трейт `Notifiable` (нічого змінювати в моделі не треба).

### 1.3 Video-рівневі publish-статуси — свідомо не використовуються

`VideoStatus` вже містить (з Phase 1) невикористані кейси `Scheduled`,
`Publishing`, `Published`, `Failed`(-для-публікації). Phase 4 їх **не
використовує**: оскільки один `Video` може одночасно мати кілька `Publication` з
різними статусами (TikTok вже published, YouTube ще scheduled), агрегований
publish-стан на `Video` немає коректного визначення. Публікаційний стан веде
виключно `Publication.status`. Це свідоме рішення, не борг — фіксується явно,
щоб наступний review не підняв питання "чому VideoStatus::Published ніде не
проставляється".

## 2. `app/Domain/Publishing/`

### 2.1 `SocialPublisherInterface`

```php
interface SocialPublisherInterface
{
    public function publish(Publication $publication): PublishResult;
}
```

### 2.2 `PublishResult` DTO

Readonly DTO: `externalPostId` (string), `metadata` (array).

### 2.3 `Providers/FakeSocialPublisher.php`

`final class`, той самий патерн що і `FakeVideoRenderer`
(`app/Domain/Video/Providers/FakeVideoRenderer.php`): метод `respondWith(PublishResult $result): static`
для налаштування в тестах, дефолтна поведінка генерує синтетичний
`externalPostId` (напр. `'fake-' . Str::uuid()'`) коли не налаштовано.

### 2.4 `app/Providers/PublishingServiceProvider.php`

`$this->app->bind(SocialPublisherInterface::class, FakeSocialPublisher::class)`,
реєструється в `bootstrap/providers.php` за тим самим патерном, що
`RenderServiceProvider`/`AssetServiceProvider`/`TtsServiceProvider`.

## 3. Captions (purpose=`captions`)

### 3.1 `GenerateCaptionsService`

`app/Domain/Publishing/Services/GenerateCaptionsService.php` — приймає
`Publication` (з завантаженими `video`, `socialAccount`), резолвить
provider/model через `LlmManagerInterface` (`purpose='captions'`,
`ContentProject` береться через `$publication->video->contentProject`),
генерує structured JSON `{caption: string, hashtags: string[]}` з тим самим
retry/repair-циклом на невалідний JSON, що і `GenerateScriptService`. Промпт
враховує платформу (`$publication->socialAccount->platform`) і контент відео
(title/description/script hook), щоб caption відрізнявся per platform.

### 3.2 `GenerateCaptionsJob`

`app/Jobs/GenerateCaptionsJob.php` — `ShouldBeUnique`
(`uniqueId()=(string) $this->publicationId`), guard на початку `handle()`:
`$publication->status !== PublicationStatus::Draft || $publication->caption !== null`
→ `return`. Черга — `default` (LLM/HTTP виклик, без ffmpeg/whisper залежностей
воркера). `$timeout=180`, `$tries=3`, `backoff()=[10,30,60]` — за аналогією з
`GenerateScriptJob`.

### 3.3 Filament

Дія "Generate Captions" на `PublicationsTable`, видима коли
`status===Draft && caption===null`, dispatch'ить `GenerateCaptionsJob` — той
самий UX-патерн, що "Generate Voiceover"/"Render Video" на `VideosTable`.

## 4. `PublishVideoJob`

`app/Jobs/PublishVideoJob.php`:

* `ShouldBeUnique`, `uniqueId()=(string) $this->publicationId` — ідемпотентність
  на рівні job (подвійний dispatch того самого publicationId не виконає job
  двічі, доки перший не завершиться/не протухне `uniqueFor`)
* `handle()`: guard `$publication->status !== PublicationStatus::Scheduled` →
  `return` (покриває сценарій, коли scheduler чи ручний dispatch спрацював
  двічі вже після завершення першого прогону — другий прогін бачить
  `Publishing`/`Published` і виходить)
* `status → Publishing` перед викликом паблішера
* Викликає `SocialPublisherInterface::publish($publication)`
* На успіх, у транзакції: `status → Published`, `published_at = now()`,
  `external_post_id = $result->externalPostId`, `metadata` мержиться з
  `$result->metadata`
* Черга — `default` (HTTP-виклик до соцмереж, не потребує ffmpeg/whisper —
  на відміну від render/whisper немає потреби у виділеній `worker`-черзі; коли
  Phase 6+ додасть реальні API-інтеграції, це рішення можна переглянути, якщо
  з'являться важкі синхронні виклики)
* `$timeout=120`, `$tries=3`, `backoff()=[15,60,120]`

## 5. Scheduler

### 5.1 Artisan command

`app/Console/Commands/DispatchDuePublicationsCommand.php`
(`publications:dispatch-due`): запит

```php
Publication::where('status', PublicationStatus::Scheduled)
    ->where('scheduled_at', '<=', now())
    ->get()
```

і `PublishVideoJob::dispatch($publication->id)` на кожен запис. Винесено в
окрему command (а не inline closure в `bootstrap/app.php`), щоб можна було
викликати з тестів через `Artisan::call('publications:dispatch-due')` і
перевіряти dispatch без реального очікування на schedule tick.

### 5.2 Реєстрація

`bootstrap/app.php`:

```php
->withSchedule(function (Schedule $schedule) {
    $schedule->command('publications:dispatch-due')
        ->everyMinute()
        ->withoutOverlapping();
})
```

## 6. Пакетний фікс: permanent job failure → видимий стан + скидання статусу

Гап, що повторюється в review з Phase 2 по 3e: 6 з 7 існуючих jobs пайплайна
(`CollectVideoAssetsJob`, `GenerateScenesJob`, `GenerateSubtitlesJob`,
`GenerateVoiceoverJob`, `QualityCheckVideoJob`, `RenderVideoJob`) на permanent
failure лише логують і лишають модель заклякнутою у проміжному статусі без
сигналу в admin panel (`GenerateScriptJob` — єдиний виняток, вже скидає
`Script`/`ContentIdea` статус). `PublishVideoJob` буде восьмим job з тим самим
потенційним гапом, якщо його не закрити зараз.

### 6.1 `App\Notifications\PipelineJobFailedNotification`

Database notification (`toDatabase()` → `['message' => ..., 'context' => ...]`).
Без mail/broadcast каналів — лише `via() => ['database']`.

### 6.2 `app/Jobs/Concerns/NotifiesOnPermanentFailure.php` (trait)

```php
protected function notifyPermanentFailure(string $logChannel, string $message, array $context): void
{
    Log::channel($logChannel)->error($message, $context);
    Notification::send(User::all(), new PipelineJobFailedNotification($message, $context));
}
```

Trait, не базовий клас — 8 jobs оперують різними моделями
(`Video`/`Script`/`Publication`) з різним конструктором і різною логікою
скидання статусу; форсувати спільний базовий клас заради одного хука
означав би рефакторинг усієї ієрархії заради мінімальної вигоди (YAGNI).
Кожен job:

1. Використовує trait
2. У власному `failed()` скидає *свою* модель на її вже наявний `Failed`-кейс
   енаму (`VideoStatus::Failed` для 6 video-jobs, `PublicationStatus::Failed`
   для `PublishVideoJob`; `GenerateScriptJob` вже має свою логіку — просто
   замінює прямий `Log::channel()->error()` на виклик trait-методу)
3. Викликає `$this->notifyPermanentFailure(...)` замість прямого
   `Log::channel(...)->error(...)`

Зачіпає 7 існуючих файлів (тільки `failed()` метод кожного) + новий
`PublishVideoJob`. Existing тести на кожен job (asserting `Log`-виклик після
`failed()`) оновлюються під нову сигнатуру виклику.

### 6.3 Filament

`AdminPanelProvider::panel()` → додати `->databaseNotifications()` — дзвіночок
з непрочитаними failure-сповіщеннями у топбарі.

## 7. Filament Resources

### 7.1 `SocialAccountResource`

CRUD: `content_project_id` select, `platform` select (enum), `external_account_id`,
`username`, `access_token`/`refresh_token` (password-masked поля,
`->password()->revealable()` як у прикладі з `.env`-секретів, ніколи не
логуються — узгоджено з encrypted casts), `status`. Ручне створення адміном —
реальних OAuth-інтеграцій ще немає (Phase 6+).

### 7.2 `PublicationResource`

CRUD: `video_id` select (searchable), `social_account_id` select (searchable,
опційно звужений до акаунтів того самого `content_project_id` що й вибраний
video — `live()` reactive-залежність між полями), `scheduled_at` datetime picker,
`caption` textarea, `hashtags` (`TagsInput::make('hashtags')` — той самий
компонент, що `MediaAssetForm`'s `TagsInput::make('metadata.tags')`, але тут
пише напряму в колонку `hashtags`, не в `metadata`), `status` badge column (read-only після
Draft — статус далі веде pipeline, не ручне редагування). Дія "Generate
Captions" (§3.3). Планування (`Draft → Scheduled`) відбувається через
редагування `scheduled_at` + збереження — форма валідує `scheduled_at` в
майбутньому і виставляє `status = Scheduled` при заповненому `scheduled_at`
на Draft-записі.

### 7.3 Calendar view

Без нової залежності: `saade/filament-fullcalendar` перевірено на Packagist
під час review спека — стабільного релізу під Filament 4 немає (лише
`v4.0.0-beta7` і новіше), тягнути beta-пакет у production заради UI-плюшки
недоцільно. "Calendar view" з ROADMAP закривається `PublicationsTable`:
фільтри за `status` і `scheduled_at` (діапазон дат, `Filter::make()` з двома
`DatePicker`), сортування за `scheduled_at` за замовчуванням — адмін бачить
хронологію публікацій без окремого календарного UI.

## 8. Тести

Feature-тести (`tests/Feature/`):

* `PublicationTest` — створення Publication через модель/фабрику
* `GenerateCaptionsJobTest` — happy-path (мокнутий LLM через `FakeLlmProvider`),
  guard на вже заповнений caption
* `PublishVideoJobTest` — happy-path (`FakeSocialPublisher`), ідемпотентність
  (подвійний `dispatchSync`/виклик `handle()` не публікує двічі — перевірка
  через guard на статус), permanent failure скидає `Publication.status` і
  створює `PipelineJobFailedNotification`
* `DispatchDuePublicationsCommandTest` — dispatch лише для due
  (`status=scheduled && scheduled_at<=now()`), не займає draft/future-scheduled
* Один репрезентативний тест на permanent-failure-нотифікацію для одного з
  6 існуючих video-jobs (щоб довести, що trait підключений і працює) —
  решта 5 лишаються покриті вже наявними тестами на `failed()`, оновленими
  під новий виклик

## DoD (розділ 24 ТЗ, пп.11-16)

11. Створити Publication — `PublicationResource` create form
12. Поставити на майбутній час — `scheduled_at` в майбутньому → `status=Scheduled`
13. Queue автоматично виконує pipeline — `publications:dispatch-due` schedule +
    `PublishVideoJob`
14. Fake Publisher змінює Publication на `published` — `FakeSocialPublisher` +
    `PublishVideoJob`
15. Повторний запуск не створює duplicate publication — `ShouldBeUnique` +
    статус-guard в `PublishVideoJob::handle()`
16. Всі помилки видимі в admin panel — §6 (пакетний failure-notification фікс)
    + `->databaseNotifications()`

Верифікація: `php artisan test`, `pint`, `route:list`, `migrate:fresh --seed`.
