# Phase 2 — Content: design spec

Джерело: `TechnicalTask.md` (розділи 4, 5, 6.1, 6.2, 7, 8, 13, 14, 19, 21, 24),
`ROADMAP.md` (Phase 2 + "Для Phase 2" carry-over з фінального review Phase 1).

## Мета

Від ідеї до готового сценарію через реальний LLM, з можливістю перемикання
провайдера/моделі per project/per purpose. Закриває пункти 1-4 Definition of Done
(розділ 24 ТЗ): Content Project → Content Idea → запуск генерації → готовий Script.

## Скоуп

Входить:

* Retype `LlmRequest.responseSchema`: `?string` → `?array` (JSON Schema), реальна
  підтримка structured output в `OpenAiLlmProvider` (`json_schema`) і
  `AnthropicLlmProvider` (forced tool-use) — розділ 13 ТЗ.
* `timeout()`/`retry()` на HTTP-рівні обох провайдерів (розділ 19 ТЗ).
* `LlmResponse.metadata` (`finish_reason`/`provider_finish_reason`), яке `LlmManager`
  дійсно пише в `LlmUsageLog.metadata` замість хардкоджених `[]`.
* `App\Models\Enums\ScriptStatus` (pending/processing/completed/failed), каст на
  `Script.status`.
* `GenerateContentIdeaService` (purpose=`idea`) — синхронний виклик, без Job.
* `GenerateScriptService` (purpose=`script`) — structured output + repair-loop при
  невалідному JSON (до 2 додаткових LLM-викликів).
* `App\Jobs\GenerateScriptJob` — idempotent (`ShouldBeUnique` + status-гард),
  timeout/tries/backoff, статуси pending/processing/completed/failed.
* Filament: `ContentProjectForm` — структуровані поля `settings.ai.*`; header action
  "Generate Idea" на `ContentIdeaResource`; row actions "Approve"/"Reject"/
  "Generate Script"; `ScriptResource` — view-only перегляд.
* Feature/Unit-тести: idea creation, script generation (мокнутий LLM), pipeline state
  transitions, ідемпотентність job, provider abstraction (розділ 21 ТЗ).
* Хвіст з Phase 1 review: прибрати хардкод `DB_HOST`/`DB_PORT` з `phpunit.xml`.

Не входить (свідомо відкладено):

* `GenerateScenesService`/`VideoScene` — Phase 3. Structured output Phase 2 навмисно
  мінімальний (`title/hook/script/estimated_duration/cta`, без `scenes[]`) — розбивку
  на сцени з `visual_query` генерує окремий LLM-виклик у Phase 3 на основі готового
  `Script.content`, що узгоджується з pipeline розділу 7 (`GenerateScriptJob` і
  `GenerateScenesJob` — окремі кроки).
* Батч-генерація кількох ідей за один виклик — `GenerateContentIdeaService` створює
  рівно одну `ContentIdea` за виклик, topic — ручний ввід користувача.
* `videos.*` NOT NULL розрив із фінального review Phase 1 — Phase 2 не створює
  `Video`, чіпати рано.
* Gemini-провайдер, asset/TTS/publishing providers — пізніші фази.

## Рішення (там, де ТЗ не фіксує деталь явно)

### `responseSchema` та structured output per provider

`LlmRequest.responseSchema` стає `?array` у форматі
`['name' => string, 'schema' => array<mixed>, 'strict' => bool]`, де `schema` — JSON
Schema (draft-2020-12-сумісна) очікуваної відповіді.

* `OpenAiLlmProvider`: `response_format = ['type' => 'json_schema', 'json_schema' =>
  ['name' => ..., 'schema' => ..., 'strict' => ...]]`.
* `AnthropicLlmProvider`: Anthropic Messages API не має власного "json_schema" режиму
  — стандартний спосіб отримати гарантовано валідний JSON це forced tool-use: один
  `tools = [['name' => $name, 'input_schema' => $schema]]`, `tool_choice = ['type' =>
  'tool', 'name' => $name]`. Відповідь приходить як `tool_use`-блок з `input` (вже
  розпарсений об'єкт) — провайдер серіалізує його назад у JSON-рядок через
  `json_encode()`, щоб `LlmResponse.content` завжди був "сирим JSON-рядком"
  незалежно від провайдера. Це єдиний контракт, на який покладається
  `GenerateScriptService`/`GenerateContentIdeaService` (`json_decode($response->content,
  true)`), без гілкування по провайдеру на рівні domain-сервісів.

### Timeout/retry на HTTP-рівні

Обидва провайдери: `Http::timeout(60)->retry(3, 500, when: fn ($e) =>
$e instanceof ConnectionException || ($e instanceof RequestException &&
$e->response->serverError()))`. Це страхує мережеві збої/5xx; 4xx (погані
креденшли, некоректний payload) не ретраїться на HTTP-рівні — там нема сенсу.
Комбінується з job-level retry (нижче) — два незалежні рівні захисту, як і вимагає
розділ 19 ТЗ.

### `LlmResponse.metadata`

Додається `finish_reason` (нормалізоване: `stop`/`length`/`error`, спільний
словник) і `provider_finish_reason` (сире значення провайдера — OpenAI
`choices[0].finish_reason`, Anthropic `stop_reason`). `LlmManager::log()` пише
`$response->metadata` в `LlmUsageLog.metadata` замість хардкоджених `[]`.
`finish_reason=length` (обрізана відповідь) — сигнал для repair-loop, що JSON міг
бути обрізаний, а не просто синтаксично невалідний; для Phase 2 обробляється так
само як будь-яка інша repair-причина (спільний шлях, без спеціального гілкування).

### `ScriptStatus` enum

На відміну від Phase 1 (де `Script.status` свідомо залишили `string` без enum, бо
job, що керує переходами, ще не існував), Phase 2 додає
`App\Models\Enums\ScriptStatus: Pending|Processing|Completed|Failed` (розділ 19 ТЗ).
Колонка вже `string` без обмежень — міграція не потрібна, тільки каст у моделі +
оновлення `ScriptFactory`.

### `GenerateContentIdeaService` — без Job

ROADMAP Phase 2 і розділ 8 ТЗ (перелік Jobs) називають лише `GenerateScriptJob`;
генерація ідеї не входить у pipeline розділу 7 (pipeline стартує вже з готової
`ContentIdea`). Тому `GenerateContentIdeaService` викликається синхронно прямо з
Filament-акції (один короткий LLM-виклик, без black-box media обробки — "важкі
media tasks НЕ виконувати в HTTP request" з розділу 8 стосується рендерингу/TTS, не
одного completion-виклику).

Вхід: `ContentProject $project, string $topic`. Purpose=`idea`, structured output
`{title: string, topic: string, score: float|null}`. Результат — новий
`ContentIdea` (`status=New`, `source='ai_generated'`, `source_data` = сира LLM-
відповідь для аудиту).

### `GenerateScriptService` — repair-loop

Вхід: `ContentIdea $idea`, `ResolvedLlmTarget $target` (провайдер+модель уже
резолвлені `LlmManager::resolve()` — сервіс сам не резолвить, щоб Job міг створити
`Script.provider`/`Script.model` до виклику LLM). Messages будуються з
`ContentProject.settings` (niche/language/tone/style) + `ContentIdea.title/topic`.
Purpose=`script`, structured output:

```json
{
    "title": "...",
    "hook": "...",
    "script": "...",
    "estimated_duration": 65,
    "cta": "..."
}
```

Repair-loop: якщо `json_decode` падає або обов'язкові ключі відсутні/не того типу —
до 2 додаткових викликів того самого провайдера/моделі з доданим у `messages`
повідомленням, що описує конкретну помилку валідації і просить повторити у
валідному форматі. Якщо після 2 повторів (3 спроби разом) валідного результату
нема — кидає `ScriptGenerationFailedException` з описом останньої помилки.
Це internal retry всередині одного виконання Job (не плутати з job-level retry —
розділ нижче).

`content`/`hook`/`estimated_duration` пишуться в одноіменні колонки `Script`,
`cta` — у `Script.metadata['cta']` (окремої колонки під `cta` в розділі 4 ТЗ нема).

### `GenerateScriptJob` — timeout/retry/ідемпотентність

```php
class GenerateScriptJob implements ShouldQueue, ShouldBeUnique
{
    public int $timeout = 180;
    public int $tries = 3;
    public function uniqueId(): string { return (string) $this->contentIdeaId; }
    public int $uniqueFor = 200; // трохи більше за $timeout — покриває виконання
    public function backoff(): array { return [10, 30, 60]; }
}
```

`ShouldBeUnique` блокує паралельний дубль-dispatch тієї самої ідеї (напр. подвійний
клік по кнопці) — другий dispatch мовчки не ставиться в чергу, поки перший не
завершився (успіхом чи permanent failure). Це страхує лише від *конкурентного*
дублю; послідовні ретраї одного dispatch (через `$tries`) виконують `handle()`
повторно — ідемпотентність у цьому випадку забезпечує внутрішній стан:

1. `ContentIdea::findOrFail($this->contentIdeaId)`. Якщо `status` не в
   `[Approved, Processing]` (тобто вже `Used`/`Rejected`/`New`) — `return` без дій
   (означає: стан вже змінився кимось іншим, або dispatch застарілий).
2. `$idea->status = Processing; $idea->save();` (no-op якщо вже `Processing` —
   ідемпотентно).
3. `$target = LlmManager::resolve($idea->contentProject, 'script');`
4. `$script = Script::firstOrCreate(['content_idea_id' => $idea->id], ['provider' =>
   $target->providerName, 'model' => $target->model, 'prompt_version' => 'v1',
   'status' => ScriptStatus::Pending]);` — на повторній спробі того самого dispatch
   знаходить уже створений рядок замість дубля.
5. `$script->update(['status' => ScriptStatus::Processing]);`
6. `GenerateScriptService::generate($idea, $target)` — виняток тут пробулькує далі
   (не ловиться в `handle()`), запускаючи стандартний job-level retry/backoff.
   `Script` лишається `Processing` між спробами (прийнятно — оновиться або на
   наступній успішній спробі, або в `failed()`).
7. Успіх → `$script->update([...content/hook/estimated_duration/metadata,
   'status' => Completed]); $idea->update(['status' => Used]);`.

`failed(Throwable $e)` (після вичерпання всіх `$tries`): перезавантажує `Script` за
`content_idea_id`, ставить `status = Failed` + `metadata['error'] = $e->getMessage()`;
`ContentIdea.status` повертається на `Approved` (не `Rejected` — розділ 4 ТЗ не має
статусу "generation failed" для ідеї, `Approved` дозволяє користувачу повторно
натиснути "Generate Script" вручну після усунення причини, наприклад зміни
provider/model у settings).

### Filament

* `ContentProjectForm`: `settings.ai.default|idea|script|quality_check|captions` —
  окремі `Select` (provider) + `TextInput`/`Select` (model, залежний список із
  `config('llm.providers.*.models')`) у `KeyValue`/repeater секції, замість сирого
  JSON-textarea — зменшує шанс невалідного `settings.ai`.
* `ContentIdeaResource`:
  * Header action `GenerateIdeaAction` (модалка: `content_project_id` select +
    `topic` text) → викликає `GenerateContentIdeaService` синхронно, показує
    success/failure notification.
  * Row action `Approve` (видима лише при `status=New`) → `status=Approved`.
  * Row action `Reject` (видима лише при `status=New`) → `status=Rejected`.
  * Row action `GenerateScript` (видима лише при `status=Approved`) →
    `GenerateScriptJob::dispatch($idea->id)`, notification "Генерацію запущено".
  * Колонка `status` як `BadgeColumn`/`TextColumn::badge()` з кольорами per статус.
* `ScriptResource`: прибирається `CreateScript`-сторінка і `canCreate()` (генерується
  лише через job, ручне створення в admin panel не має сенсу). `EditScript`
  лишається як `Pages\ViewScript` (перейменування; форма ставить усі поля
  `disabled()` — це перегляд деталей, не редагування). Таблиця: `content_idea.title`,
  `provider`, `model`, `status` (badge), `estimated_duration`, `created_at`.

### Тести

* Unit: `GenerateScriptServiceTest` (валідний з 1-го разу; невалідний → repair →
  валідний; невалідний 3 рази → exception); розширення
  `OpenAiLlmProviderTest`/`AnthropicLlmProviderTest` на новий формат
  `responseSchema` (json_schema / forced tool-use) та timeout/retry-конфігурацію
  HTTP-клієнта.
* Feature: `GenerateContentIdeaServiceTest` (створює `ContentIdea` з Fake LLM);
  `GenerateScriptJobTest` — happy path (статуси `ContentIdea`/`Script` проходять усі
  переходи), ідемпотентність (подвійний dispatch/повторний виклик `handle()` не
  створює другий `Script`), permanent failure (`Script=Failed`,
  `ContentIdea=Approved` після вичерпання `$tries`).
* `phpunit.xml`: прибрати хардкод `DB_HOST`/`DB_PORT=5432`, покладатись на
  `.env.testing` (задокументовано в README, порти лишаються керовані звідти).

## Acceptance criteria (DoD, з ROADMAP.md Phase 2 = пункти 1-4 розділу 24 ТЗ)

* Можна створити `ContentProject` з `settings.ai.script` (провайдер/модель, відмінні
  від дефолтних).
* Можна створити `ContentIdea` через Filament-акцію `GenerateIdeaAction` (реальний
  LLM-виклик через `FakeLlmProvider` у тестах, реальний provider у dev).
* Approve ідеї → `GenerateScript` row action запускає `GenerateScriptJob`.
* Job резолвить provider/model саме з `ContentProject.settings.ai.script` (не
  хардкод) і за успіху дає `Script.status=Completed` з заповненими
  `content/hook/estimated_duration`.
* `php artisan test`, `vendor/bin/pint --test`, `php artisan route:list`,
  `php artisan migrate:fresh --seed` проходять чисто.
