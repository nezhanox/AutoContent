# Phase 3a — Scenes: design spec

Джерело: `TechnicalTask.md` (розділи 4, 7, 8, 13, 19), `ROADMAP.md` (Phase 3, частина
"GenerateScenesService → VideoScene[]").

## Мета

Від готового `Script` до `Video` з упорядкованим списком `VideoScene[]` через LLM,
без нової інфраструктури (жодного FFmpeg/TTS/Whisper — це наступні під-фази 3b/3c/3d).
Це перший крок Phase 3 і перший момент, коли рядок `Video` взагалі створюється —
розблоковує NOT NULL FK-дизайн з Phase 1 (`Video.content_project_id`/`content_idea_id`/
`script_id`/`description` вимагають існуючого `Script`).

## Скоуп

Входить:

* `app/Domain/Video/Services/GenerateScenesService.php` — structured output + repair-loop
  (той самий патерн, що `GenerateScriptService` з Phase 2), `purpose='script'`.
* `app/Domain/Video/Exceptions/SceneGenerationFailedException.php`.
* ALTER-міграція: unique index на `videos.script_id`.
* `app/Jobs/GenerateScenesJob.php` — idempotent (`ShouldBeUnique` + unique DB-індекс),
  timeout/retry, створює `Video` (вперше в проєкті) і `VideoScene[]`.
* Filament: row action "Generate Scenes" на `ScriptsTable`.
* Feature/Unit-тести: сервіс (repair-loop), job (створення Video/сцен, ідемпотентність,
  no-op guard), Filament action.

Не входить (свідомо відкладено):

* `AssetProviderInterface`/`MediaAsset`-прив'язка (`VideoScene.asset_id` лишається
  `null`) — Phase 3b.
* `TtsProviderInterface`/`Voiceover` — Phase 3b.
* Whisper/субтитри — Phase 3c.
* `FfmpegVideoRenderer`/рендеринг/`QualityCheckJob` — Phase 3d.
* Перетворення `VideoResource`/`VideoSceneResource` на view-only (Phase 1
  auto-generated CRUD лишається як є) — рішення про це відкладено до 3d, коли увесь
  пайплайн запрацює, за тим самим підходом, що й `ScriptResource` у Phase 2.
* Новий `purpose='scenes'` у `config/llm.php`/`ContentProjectForm` — свідомо
  перевикористовуємо `purpose='script'`.

## Рішення (там, де ТЗ не фіксує деталь явно)

### `GenerateScenesService` — repair-loop і схема

Сигнатура: `generate(Script $script, ResolvedLlmTarget $target): array` — повертає
масив сцен (`array<int, array{type: string, duration: int, visual_query: ?string,
text: string}>`), без побічних ефектів у БД (створення `Video`/`VideoScene` —
відповідальність `GenerateScenesJob`, той самий розподіл, що сервіс/job у Phase 2).

JSON Schema:

```json
{
    "name": "video_scenes",
    "schema": {
        "type": "object",
        "properties": {
            "scenes": {
                "type": "array",
                "items": {
                    "type": "object",
                    "properties": {
                        "type": {"type": "string", "enum": ["hook", "broll", "screen_recording", "image", "screenshot", "text", "generated_video", "transition", "cta"]},
                        "duration": {"type": "integer"},
                        "visual_query": {"type": ["string", "null"]},
                        "text": {"type": "string"}
                    },
                    "required": ["type", "duration", "visual_query", "text"],
                    "additionalProperties": false
                }
            }
        },
        "required": ["scenes"],
        "additionalProperties": false
    },
    "strict": true
}
```

`type` валідується як один з `VideoSceneType`-кейсів (рядкове значення enum-а).
Repair-loop: до 2 додаткових LLM-викликів при невалідному JSON/відсутньому чи
невірно типізованому полі (та сама механіка, що `GenerateScriptService::generate()`
— помилка валідації додається в `messages`, до 3 спроб разом), потім
`SceneGenerationFailedException`. Сума `duration` по сценах **не** валідується проти
`Script.estimated_duration` — це якісна характеристика тексту від LLM, не ознака
коректності JSON; додавання такої перевірки лише збільшує шанс хибних
repair-ітерацій через природну неточність LLM в оцінці тривалості.

`purpose='script'` при виклику `LlmManager::complete()` — свідомо не заводимо
окремий `purpose='scenes'`: генерація сцен концептуально є продовженням
script-роботи, і `ContentProjectForm` (Phase 2) не має под це окремого поля.
`providerOverride`/`modelOverride` з `$target` (той самий підхід, що
`GenerateScriptService`, — job резолвить провайдера один раз, сервіс тримає його
фіксованим на всіх repair-спробах).

### `Video` — створення, unique index, title/description

Unique-індекс на `videos.script_id` (нова ALTER-міграція) — закриває м'яке місце,
відзначене у фінальному review Phase 2 (`GenerateScriptJob`'s `firstOrCreate` без
DB-рівня гарантії). Це перший job у проєкті, де dispatch двох конкурентних
екземплярів справді міг би створити 2 записи без цього індексу.

`Video.title = Script.metadata['title']`, `Video.description = Script.hook` —
обидва поля вже існують у `Script` з Phase 2 (`GenerateScriptService` записує
`title` в `metadata['title']`, `hook` — в окрему колонку), додаткового LLM-виклику
не потрібно.

`Video.status` при створенні — `VideoStatus::ScriptGenerated`. У `VideoStatus` enum
(Phase 1: draft/script_generated/voice_generated/assets_ready/rendering/rendered/
approved/scheduled/publishing/published/failed) немає окремого значення "сцени
згенеровано" — це свідомий вибір: `Video` не існує до моменту, коли `Script` уже
`Completed`, тож щойно створений `Video` одразу семантично "має скрипт", а
генерація сцен — внутрішня деталь переходу до `VoiceGenerated` (Phase 3b), а не
окремий видимий статус. Мігрувати enum під нове значення немає підстав — воно
нічого не додає до того, що вже виражає структурна властивість "`Video.scenes()`
непорожній".

### `GenerateScenesJob` — ідемпотентність і retry

```php
class GenerateScenesJob implements ShouldQueue, ShouldBeUnique
{
    public int $timeout = 180;
    public int $tries = 3;
    public function uniqueId(): string { return (string) $this->scriptId; }
    public int $uniqueFor = 200;
    public function backoff(): array { return [10, 30, 60]; }
}
```

Ті самі значення, що `GenerateScriptJob` (Phase 2) — немає підстави відрізнятись,
розмір LLM-виклику подібний.

`handle()`:

1. `Script::findOrFail($this->scriptId)`. Якщо `status !== ScriptStatus::Completed`
   — вихід без дій (job міг бути диспатчений для ще не готового сценарію).
2. `$target = $llmManager->resolve($script->contentIdea->contentProject, 'script');`
3. `$video = Video::firstOrCreate(['script_id' => $script->id], ['content_project_id'
   => ..., 'content_idea_id' => ..., 'title' => ..., 'description' => ...,
   'status' => VideoStatus::ScriptGenerated]);` — unique-індекс з БД гарантує, що
   конкурентний дубль-dispatch (той, що `ShouldBeUnique` не встиг заблокувати)
   отримає той самий рядок, а не впаде з integrity-помилкою (`firstOrCreate`
   повторює `INSERT` як `SELECT` при `UniqueConstraintViolationException` —
   стандартна Laravel-поведінка).
4. `$video->scenes()->delete();` — прибирає всі наявні сцени перед (пере)генерацією.
   Свідомий вибір: retry чи повторний ручний dispatch завжди перегенеровує сцени
   "з нуля" одним когерентним набором, а не намагається злити частковий і новий
   результат. Ціна — LLM-виклик при кожному повторі не дешевий, але Filament-кнопка
   (крок 5) ховається щойно `Video` існує, тож єдиний реалістичний шлях до
   повторного виклику — легітимний retry після падіння.
5. Виклик `GenerateScenesService::generate($script, $target)`, створення
   `VideoScene`-рядків у порядку масиву (`order` = індекс), `asset_id = null`.

`failed(Throwable $exception)` — логування в канал `video` (за аналогією з
`content`-каналом у `GenerateScriptJob::failed()`); `Video.status` лишається
`ScriptGenerated` (немає окремого "failed"-сигналу на рівні `Video` для цього
кроку — сцени просто відсутні, Filament-кнопка "Generate Scenes" знову зникла б
через `!videos()->exists()`, тож потрібен ручний retry через artisan/tinker до
появи повноцінного pipeline-статусу в 3d; прийнятно для 3a, не є DoD-вимогою).

### Filament

Row action "Generate Scenes" на `ScriptsTable` (поруч із наявним `ViewAction`,
Task 10 з Phase 2 зробив ресурс view-only лише щодо Create/Edit, не щодо
кастомних Actions):

```php
Action::make('generateScenes')
    ->label('Generate Scenes')
    ->visible(fn (Script $record): bool => $record->status === ScriptStatus::Completed
        && ! $record->videos()->exists())
    ->requiresConfirmation()
    ->action(function (Script $record): void {
        GenerateScenesJob::dispatch($record->id);
        Notification::make()->title('Scene generation queued')->success()->send();
    }),
```

### Тести

* Unit: `GenerateScenesServiceTest` — валідний з 1-го разу; невалідний → repair →
  валідний; вичерпання спроб → `SceneGenerationFailedException`; невірний `type`
  (поза enum) → repair.
* Feature: `GenerateScenesJobTest` — happy path (Video створюється з правильними
  title/description/status, сцени з правильним `order`), no-op якщо
  `Script.status !== Completed`, повторний `handle()` видаляє й перестворює сцени
  без дублю `Video` (unique index), `failed()` логує в канал `video`.
* Feature: Filament-тест на видимість/dispatch дії `generateScenes` (аналогічно
  `ContentIdeaActionsTest` з Phase 2).

## Acceptance criteria (для 3a, частина ширшого DoD Phase 3 — пункт 6 розділу 24 ТЗ)

* Можна натиснути "Generate Scenes" на завершеному `Script` і отримати `Video`
  (з коректними title/description/status) і впорядкований список `VideoScene`,
  видимий через існуючий (Phase 1) `VideoSceneResource`.
* Повторний dispatch того самого `Script` не створює другий `Video`
  (DB-рівня unique, не лише job-рівня).
* `php artisan test`, `vendor/bin/pint --test`, `php artisan route:list`,
  `php artisan migrate:fresh --seed` проходять чисто.
