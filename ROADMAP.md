# AI Content Factory — Roadmap виконання

Джерело вимог: `TechnicalTask.md`. Цей файл деталізує розділ 26 ТЗ до рівня, придатного
для послідовної роботи "фаза за фазою": для кожної фази перед стартом пишеться окремий
spec-файл (`docs/superpowers/specs/YYYY-MM-DD-phaseN-<topic>-design.md`) і implementation
plan, потім — реалізація і верифікація (`php artisan test`, `pint`, `route:list`,
`migrate:fresh --seed`).

Фази виконуються послідовно. Наступна фаза не стартує, поки не закрито DoD попередньої.

Статус: `[ ]` не почато · `[~]` в роботі · `[x]` завершено.

---

## Phase 0 — Bootstrap ✅ завершено (2026-09-11)

Мета: підготувати репозиторій і оточення, без бізнес-логіки.

Deliverables:

* [x] Git-репозиторій ініціалізовано, `.gitignore`, README-заглушка
* [x] `composer create-project laravel/laravel` (PHP 8.4+, Laravel 12+)
* [x] Docker Compose: `app`, `nginx`, `postgres`, `redis`, `worker`, `horizon`
      (розділ 22 ТЗ); FFmpeg доступний у `worker`
* [x] `.env.example` з усіма ключами з розділу 18 ТЗ (без значень)
* [x] Базова структура директорій під `app/Domain/...` (розділ 5, 25 ТЗ)
* [x] `php artisan test`, `php artisan pint` виконуються без помилок на порожньому проєкті

DoD: `docker compose up` піднімає застосунок, `/up` health-check відповідає 200. **Перевірено.**

Spec: `docs/superpowers/specs/2026-09-11-phase0-bootstrap-design.md`
Plan: `docs/superpowers/plans/2026-09-11-phase0-bootstrap.md`

Відомі, свідомо відкладені до Phase 1 моменти (з фінального review):
* `horizon`-контейнер завершується помилкою `Command "horizon" is not defined`, доки не встановлено `laravel/horizon` — очікувано, встановлення пакета належить Phase 1.
* Немає Docker healthchecks/`depends_on: condition: service_healthy` — не заважає Phase 0, але Phase 1 (`migrate:fresh --seed`) може отримати race на старті стеку — варто додати тоді ж.
* Тестова БД — стокова SQLite, тоді як застосунок працює на Postgres — потрібне свідоме рішення на старті Phase 1 (окрема test-БД на pgsql чи свідомо лишити SQLite і врахувати це в міграціях).

---

## Phase 1 — Foundation ✅ завершено (2026-09-12)

Мета: домен, сховище, admin panel, базова AI-абстракція готові до підключення pipeline.

Deliverables:

* [x] PostgreSQL, Redis, Horizon, Filament підключені й сконфігуровані
* [x] Усі моделі + міграції з розділу 4 ТЗ: `User`, `ContentProject`, `ContentIdea`,
      `Script`, `Video`, `VideoScene`, `MediaAsset`, `Voiceover`, `SocialAccount`,
      `Publication`, `VideoMetric`, `LlmUsageLog`
* [x] Factories + Seeders для всіх моделей
* [x] `config/llm.php`: реєстр providers/models, дефолти, ціна/1K токенів
* [x] `LlmProviderInterface::complete()`, `LlmRequest`/`LlmResponse` DTO (розділ 6.1 ТЗ)
* [x] `LlmManagerInterface` + резолвінг provider/model за пріоритетом
      override → project.settings.ai.<purpose> → project.settings.ai.default → .env
      (розділ 6.2 ТЗ)
* [x] `OpenAiLlmProvider`, `AnthropicLlmProvider` (2 реальних, через Laravel `Http`,
      без SDK), `FakeLlmProvider` для тестів
* [x] Логування кожного виклику LLM у `LlmUsageLog`
* [x] Logging channels: `content`, `video`, `publishing`, `ai` (розділ 20 ТЗ)
* [x] Encrypted casts для `SocialAccount.access_token`/`refresh_token`

DoD: у Filament видно всі 11 розділів моделей; можна створити `ContentProject` з
`settings.ai` і отримати правильний resolved provider/model через unit-тест на
`LlmManager` (з `FakeLlmProvider`, без реальних API-викликів). **Перевірено** —
33/33 тести, `pint`, `route:list`, `migrate:fresh --seed`, `docker compose up`
(усі 6 сервісів, `horizon` більше не падає).

Spec: `docs/superpowers/specs/2026-09-11-phase1-foundation-design.md`
Plan: `docs/superpowers/plans/2026-09-11-phase1-foundation.md`

**Для Phase 2 — врахувати (з фінального review Phase 1):**
* `LlmRequest.responseSchema` наразі лише прапорець для OpenAI (`response_format:
  json_object`) і повністю ігнорується `AnthropicLlmProvider`. Розділ 13 ТЗ
  ("structured output + JSON-схема, retry/repair") вимагає реальної передачі схеми
  в обох провайдерах — потрібно ретипізувати DTO (`?string` → `?array`) і додати
  підтримку в кожного провайдера (OpenAI `json_schema`, Anthropic tool-use).
* `LlmResponse.metadata` ніде не заповнюється провайдерами і не потрапляє в
  `LlmUsageLog` (хардкод `[]`) — `finish_reason`/`stop_reason` знадобиться саме для
  retry/repair-логіки з розділу 13.
* Жоден провайдер не має явних `timeout()`/`retry()` — дефолтні 30с Laravel закороткі
  для реальної генерації сценарію; розділ 19 ТЗ явно вимагає retry/timeout.
* `videos.script_id`/`content_idea_id`/`description` — NOT NULL за дизайном (Video
  створюється тільки після існування Script, узгоджено з NOT NULL `video_id` на
  `VideoScene`/`Voiceover`). Якщо реальний дизайн `GenerateScriptJob`/
  `GenerateScenesJob` потребуватиме "чернеткового" Video ще до Script — треба буде
  одна ALTER-міграція.
* `phpunit.xml` досі хардкодить `DB_HOST`/`DB_PORT=5432` — на машинах, де ці порти
  зайняті іншим проєктом, тести мовчки підключаються не туди, якщо не виставити
  `DB_PORT`/`DB_HOST` вручну (working, задокументовано в README, але не усунуто
  структурно). Чисте рішення: прибрати ці два рядки з `phpunit.xml` і покладатись на
  `.env.testing`.
* `LlmUsageLog.cost` має `decimal:6` cast (фіксує точність), але Laravel повертає
  decimal-cast як рядок, не float — якщо знадобиться справжній numeric-тип, потрібен
  кастомний cast.

---

## Phase 2 — Content ✅ завершено (2026-09-12)

Мета: від ідеї до готового сценарію через реальний LLM, з можливістю перемикання моделі.

Deliverables:

* [x] `GenerateContentIdeaService` (purpose=`idea`)
* [x] `GenerateScriptService` (purpose=`script`) — structured output + JSON-схема,
      retry/repair при невалідному JSON (розділ 13 ТЗ)
* [x] `GenerateScriptJob`: idempotent, retry, timeout, статуси pending/processing/
      completed/failed (розділи 8, 19 ТЗ)
* [x] Filament: створення `ContentProject` (з `ai` config per purpose), `ContentIdea`,
      перегляд `Script`
* [x] Feature-тести: idea creation, script generation (мокнутий LLM), pipeline state
      transitions, provider abstraction (розділ 21 ТЗ)

DoD: пункти 1–4 з Definition of Done (розділ 24 ТЗ) — можна створити Project → Idea →
запустити генерацію → отримати `Script`, при цьому провайдер/модель беруться з
`ContentProject.settings.ai.script`, а не хардкодяться. **Перевірено** — 60/60 тестів,
`pint`, `route:list`, `migrate:fresh --seed`, наскрізний тест підтверджує резолвінг
provider/model саме з `settings.ai.script` (не з глобального дефолту).

Spec: `docs/superpowers/specs/2026-09-12-phase2-content-design.md`
Plan: `docs/superpowers/plans/2026-09-12-phase2-content.md`

**Для Phase 3 — врахувати (з фінального review Phase 2):**
* `ScriptResource::resolveRecordRouteBinding()` (фікс Postgres bigint vs non-numeric
  route key → 500 замість 404) наразі точковий на одному ресурсі; той самий розрив є
  на `/{record}/edit` кожного іншого ресурсу — вартує спільного guard'а в базовому
  `Resource`, а не per-resource.
* `GenerateScriptJob.$uniqueFor = 200` не покриває повний ланцюжок retry/backoff
  (~900с у гіршому випадку) — сьогодні закрито лише видимістю кнопки у Filament, не
  самою job; відкриється, якщо з'явиться недорожній dispatcher (напр. Phase 3
  autopilot).
* `ContentIdeaForm`'s `status` Select пропонує всі 5 `ContentIdeaStatus` без гарду —
  адмін може вручну виставити `processing`/`used` і розсинхронити pipeline-стани.
* `GenerateContentIdeaService` зберігає в `ContentIdea.source_data` розпарсений масив,
  а не сиру LLM-відповідь — розходиться зі спек-вимогою "сира відповідь для аудиту".
* Permanent job failure не дає користувачу видимого сигналу окрім логу —
  `Notification::make()->sendToDatabase()` закрив би цю петлю.
* `GenerateScriptService`'s repair-loop `providerOverride`/`modelOverride`-wiring не
  має власного тесту, що відрізняв би його від збігу з `settings.ai.script`-фолбеком
  (докладніше в review workspace, вже видаленому — суть: наскрізний DoD-тест ловить
  регресію на рівні `Script`-рядка, але не саме це внутрішнє переналаштування).

---

## Phase 3 — Video

Мета: від сценарію до готового `1080x1920.mp4`.

Deliverables:

* [ ] `GenerateScenesService` → `VideoScene[]`
* [ ] `MediaAsset` (локальні/stock assets), `AssetProviderInterface` (без прив'язки до
      конкретного stock-провайдера, розділ 12 ТЗ)
* [ ] `TtsProviderInterface` + `ElevenLabsTtsProvider`, `GenerateVoiceoverService`,
      `GenerateVoiceoverJob`
* [ ] Subtitles: Whisper як Python CLI worker, Laravel отримує structured JSON
      (розділ 11 ТЗ), генерація ASS/SRT
* [ ] `VideoRendererInterface` + `FfmpegVideoRenderer` (Symfony Process, без хардкоду
      параметрів — розділ 9–10 ТЗ), configurable vertical template
* [ ] `RenderVideoJob`, `QualityCheckJob` (purpose=`quality_check` через `LlmManager`)
* [ ] Feature-тести: scene generation, rendering pipeline (мокнутий FFmpeg/TTS/Whisper)

DoD: пункти 5–9 DoD (розділ 24 ТЗ) — Voiceover, Video Scenes, subtitles, rendering,
готовий `.mp4`, перегляд у Filament.

---

## Phase 4 — Publishing

Мета: черга публікацій, ідемпотентність, multi-platform caption/hashtags.

Deliverables:

* [ ] `SocialPublisherInterface` + `FakeSocialPublisher`
* [ ] `SocialAccount`, `Publication` CRUD у Filament + Calendar view
* [ ] `PublishVideoJob`: ідемпотентний (unique job / lock по `publication_id`)
* [ ] Laravel Scheduler: `publications.status=scheduled AND scheduled_at<=now()` →
      dispatch `PublishVideoJob` (розділ 16 ТЗ)
* [ ] Генерація caption/hashtags per platform (purpose=`captions` через `LlmManager`) —
      один `Script`/`ContentIdea` може мати різні `Video`/`Publication`/Caption для
      TikTok/YouTube/Instagram/X (розділ 27 ТЗ, критична вимога — не прив'язувати
      `ContentIdea`/`Script` до конкретної платформи)
* [ ] Feature-тести: publication creation, duplicate publication prevention, failed jobs

DoD: пункти 11–16 DoD (розділ 24 ТЗ) — Publication, scheduled_at, автоматичний запуск
через Queue, `FakePublisher` → `published`, повторний запуск без дублю, помилки видно в
admin panel.

---

## Phase 5 — Analytics

Мета: базова аналітика по контенту й по вартості LLM.

Deliverables:

* [ ] `CollectMetricsService`/`CollectVideoMetricsJob` → `VideoMetric`
* [ ] Filament Dashboard: videos generated today, published, failed jobs, views, likes,
      comments, best videos, top performing topics (розділ 14 ТЗ)
* [ ] Dashboard/звіт по `LlmUsageLog`: вартість і токени по provider/model/purpose —
      основа для рішення, яку модель використовувати далі (розділ 6.2 ТЗ)

DoD: усі 16 пунктів Definition of Done (розділ 24 ТЗ) закриті; `php artisan test`,
`pint`, `route:list`, `migrate:fresh --seed` проходять чисто.

---

## Phase 6+ — Post-MVP (поза межами поточного скоупу)

Не починати, поки Phase 0–5 не стабілізовані:

* Реальні Social API інтеграції (TikTok/YouTube/Instagram/X) замість `FakeSocialPublisher`
* Додаткові LLM-провайдери (Gemini та інші) і додаткові asset-провайдери
  (Pexels/Pixabay/Unsplash/AI generators)
* Автоматичне визначення viral-контенту, складніша оптимізація на основі `VideoMetric`
  і `LlmUsageLog`
