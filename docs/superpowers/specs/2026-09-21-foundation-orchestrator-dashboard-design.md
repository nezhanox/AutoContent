# Foundation: авто-оркестрація + дашборд статусів — design

Джерело вимог: запит користувача в чаті (не з `TechnicalTask.md`) — перший
під-проєкт з декомпозиції більшого запиту "автоматизована генерація відео за
темою, з дашбордом і кількома джерелами контенту" (книги, YouTube, новини,
соцмережі-історії, історичні теми — кожен буде окремим спеком пізніше).

## Проблема

Пайплайн (`GenerateScriptJob` → `GenerateScenesJob` → `GenerateVoiceoverJob` →
`CollectVideoAssetsJob` → `GenerateSubtitlesJob` → `RenderVideoJob` →
`QualityCheckVideoJob`) вже існує й ідемпотентний, але немає жодного способу
запустити його одним кліком — оператор вручну тисне 7 окремих кнопок у
Filament, дивлячись на `Video.status`. Немає єдиного місця, де видно "на якому
етапі впало і чому" — 6 з 7 video-jobs встановлюють `VideoStatus::Failed`, але
не пишуть причину нікуди, крім логів і `PipelineJobFailedNotification`.

## 1. Дані

### 1.1 `videos.failed_stage`

Нова міграція:

```php
$table->string('failed_stage')->nullable()->after('error_message');
```

Значення — один із семи стрінгових ідентифікаторів стадій: `script`, `scenes`,
`voiceover`, `assets`, `subtitles`, `render`, `quality_check`. `error_message`
(уже існує на `videos`, зараз заповнюється лише... ніким — 6 з 7 video-jobs
його ігнорують) починає заповнюватись поряд із `failed_stage` в кожному
`failed()`-методі. На успішному завершенні стадії обидва поля скидаються в
`null` (щоб дашборд не показував стару помилку після успішного retry).

### 1.2 `content_projects.settings` — нові ключі

Без міграції (`settings` вже `jsonb`):

* `settings.tts.voice` — ElevenLabs voice id за замовчуванням для каналу.
  (Поле вже читається в `GenerateVoiceoverService::resolveVoiceSettings()` —
  зараз просто ніде не виставляється через UI.)
* `settings.platforms` — `array<string>` (значення `SocialPlatform::value`) —
  куди канал типово публікується. Тільки зберігається й показується;
  `PublishVideoJob`/реальні API — поза межами цього спеку.

### 1.3 `content_projects.target_platforms` — виправлення наявного поля

`ContentProjectForm` зараз рендерить `target_platforms` як `TextInput::make(...)
->disabled()` (сире `[]`, нередаговане — фактичний dead-end у формі). Замінюється
на `CheckboxList::make('target_platforms')->options(SocialPlatform-мапа)`.
`settings.platforms` (§1.2) — окреме поле, бо `target_platforms` семантично вже
"на яких платформах existує канал" (`SocialAccount` прив'язки), а
`settings.platforms` — "куди генератор типово готує публікацію відразу після
рендеру". Дублювання не вводимо: якщо після імплементації виявиться, що це
одне й те саме поле — settings.platforms прибирається на користь
target_platforms в імплементації, це рішення лишається на розсуд імплементації,
головне — не мати двох джерел правди в фінальному коді.

## 2. Оркестрація — кожен job дописує наступний

Жодного `Bus::chain`. У кінці успішного `handle()` кожного з 6 перших jobs —
`dispatch()` наступного:

```
GenerateScriptJob   → (успіх) → GenerateScenesJob::dispatch($script->id)
GenerateScenesJob    → (успіх) → GenerateVoiceoverJob::dispatch($video->id)
GenerateVoiceoverJob → (успіх) → CollectVideoAssetsJob::dispatch($video->id)
CollectVideoAssetsJob→ (успіх) → GenerateSubtitlesJob::dispatch($video->id)
GenerateSubtitlesJob → (успіх) → RenderVideoJob::dispatch($video->id)
RenderVideoJob       → (успіх) → QualityCheckVideoJob::dispatch($video->id)
QualityCheckVideoJob → кінець ланцюга, нічого не диспетчить
```

Обґрунтування (а не `Bus::chain`): кожен job вже має власний ідемпотентний
guard (перевіряє попередній статус на початку `handle()`), тому ланцюжок із
прямих `dispatch()`-викликів природно переживає рестарт воркера й дозволяє
retry рівно однієї стадії без повторного знання про решту ланцюга. `Bus::chain`
вимагає знати всі 7 jobs наперед у момент створення ланцюга і ускладнює
per-stage retry (весь `Batch` або нічого).

Ручний тригер кожної стадії з `VideosTable` (наявні кнопки "Generate
Voiceover"/"Collect Assets"/... ) **лишається** — авто-ланцюжок не забирає
можливість вручну передиспетчити одну стадію (це і є механізм retry, §3.3).

Публікація (`PublishVideoJob`) **не входить** у ланцюжок — зупиняється на
`QualityCheckVideoJob`. Це свідоме рішення (підтверджено користувачем):
відео має бути готове для перегляду, публікація лишається окремою ручною дією
в наявному `PublicationResource`.

## 3. Filament UI

### 3.1 "Generate Video" — header action

Новий header action (за зразком наявного `generateIdea` в
`ListContentIdeas.php:14-46`, той самий UX: модалка з формою, без нової
сторінки) — додається на `ListVideos`-сторінку (`app/Filament/Resources/Videos/Pages/ListVideos.php`):

```php
Action::make('generateVideo')
    ->label('Generate Video')
    ->schema([
        Select::make('content_project_id')
            ->options(fn (): array => ContentProject::query()->pluck('name', 'id')->all())
            ->required(),
        TextInput::make('topic')->required(),
    ])
    ->action(function (array $data): void {
        $idea = app(GenerateContentIdeaService::class)->generate(
            ContentProject::findOrFail($data['content_project_id']),
            $data['topic'],
        );
        $idea->update(['status' => ContentIdeaStatus::Approved]);

        GenerateScriptJob::dispatch($idea->id);

        Notification::make()->title('Generation started')->success()->send();
    })
```

`GenerateContentIdeaService::generate()` вже сам звертається до LLM і
перетворює вільний `topic`-текст на структуровані `title`/`topic`/`score`
(`app/Domain/Content/Services/GenerateContentIdeaService.php:16-33`) — нічого
додаткового писати не треба, лишається одразу проставити `Approved` (замість
`New`, який чекає ручного review) і задиспетчити перший job ланцюга.

### 3.2 Прогрес по етапах — колонка + inline-перегляд на `VideosTable`

Нова обчислювана колонка `stage` (не з БД напряму — маппинг з `Video.status`
+ `failed_stage` на людський лейбл і badge-колір: "Сценарій" / "Сцени" /
"Озвучка" / "Активи" / "Субтитри" / "Рендер" / "Перевірка якості" / "Готово" /
"Помилка: {failed_stage}"). `ViewAction` (наявний Filament компонент, не
кастомна сторінка) відкриває наявну `VideoForm`-схему, розширену
секціями-переглядами:

* Сценарій — `script.content`/`script.hook` (текст, read-only)
* Сцени — таблиця `video_scenes` (order, type, duration, visual_query, asset)
* Озвучка — `<audio>` плеєр на `voiceover.file_path` (Filament
  `FileUpload`/`Placeholder` з посиланням на `Storage::url()`, чи просто
  `Infolists\Components\Audio` якщо є в поточній версії Filament — вимагає
  перевірки під час імплементації, інакше — просте посилання на файл)
* Субтитри — текст `.srt`
* Фінальне відео — `<video>` плеєр на `file_path`
* Quality report — вже існуюче поле `quality_report` (JSON), просто
  показується читабельно (наявний `KeyValue`-компонент Filament)

### 3.3 Retry

На `failed`-записі (`status === VideoStatus::Failed`) — одна кнопка "Retry",
що мапить `failed_stage` на відповідний job-клас (просто `match` на 7
значень) і передиспетчить його з тим самим `video_id`/`script_id`. Той самий
job, який провалився — не спеціальний "retry job".

### 3.4 Налаштування каналу

`ContentProjectForm` (`app/Filament/Resources/ContentProjects/Schemas/ContentProjectForm.php`):

* `target_platforms` → `CheckboxList` (§1.3)
* Нове поле `Select::make('settings.tts.voice')` — опції або хардкод-список
  (якщо ElevenLabs API не викликається з форми), або підвантажені раз через
  `Cache::remember` з `GET /v1/voices` (рішення — на розсуд імплементації,
  без live-виклику на кожен рендер форми)
* Нове `CheckboxList::make('settings.platforms')` — той самий список
  платформ, що й `target_platforms`

## 4. Поза межами цього спеку

* Реальна публікація (OAuth, TikTok/YouTube/Instagram API) — лишається
  `FakeSocialPublisher`, `settings.platforms` тільки зберігається
* Шаблони стилю з відео-прикладів — окремий спек
* П'ять джерел контенту (книги/YouTube/новини/соцмережі/історія) — окремі
  спеки, кожен використовує цей самий ланцюжок як фундамент, підключаючись
  лише на етапі "звідки взявся `topic`/`ContentIdea`"
* Аналітика переглядів/витрат — витрати вже логуються в `LlmUsageLog`,
  перегляди залежать від реальної публікації (не існує поки що)

## 5. Тести

* Оновлення 6 існуючих `*JobTest.php` (`GenerateScriptJobTest`,
  `GenerateScenesJobTest`, `GenerateVoiceoverJobTest`,
  `CollectVideoAssetsJobTest`, `GenerateSubtitlesJobTest`,
  `RenderVideoJobTest`) — новий `assert`, що на успіх наступний job
  задиспетчений (`Queue::fake()` + `Queue::assertPushed(NextJob::class, ...)`)
* Оновлення `failed()`-тестів усіх 6 video-jobs — тепер асертують і
  `failed_stage`, і `error_message` на моделі `Video`
* Новий `GenerateVideoActionTest` (Filament) — за зразком наявних
  `Video*ActionTest.php`: header action створює `ContentIdea` (Approved) і
  диспетчить `GenerateScriptJob`
* Новий feature-тест "end-to-end ланцюжок": `Queue::fake()`, ручний виклик
  `handle()` кожного job по черзі (як у наявних тестах), асертує що кожен
  наступний job був запушений — без реального піднятого воркера

## DoD

1. Одна дія "Generate Video" (канал + тема текстом) запускає весь пайплайн
   без ручного натискання 7 кнопок
2. Провал будь-якої стадії видно на `VideosTable` з причиною і назвою стадії
3. "Retry" на провалі передиспетчить саме ту стадію, що впала
4. Канал має налаштовуваний голос за замовчуванням і платформи публікації
5. Публікація лишається ручною дією — жодне відео не публікується
   автоматично

Верифікація: `php artisan test`, `pint`, ручний прогін "Generate Video" з
реальними ключами (DeepSeek/ElevenLabs) до готового відео без жодного
додаткового ручного дії, крім початкової форми.
