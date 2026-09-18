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

Фаза розбита на під-фази (3a → 3b → 3c → 3d → 3e), кожна зі своїм spec/plan циклом —
масштаб надто великий для одного spec/plan (п'ять практично незалежних підсистем).
Початковий поділ (3a/3b/3c/3d) під час брейнштормінгу 3b розбито ще раз: Assets і
Voiceover виявились незалежними підсистемами порівнянного з 3a масштабу, тож Assets
переїхав з 3b у власну 3c, а Subtitles/Rendering зсунулись у 3d/3e.

* [x] `GenerateScenesService` → `VideoScene[]` — **Phase 3a, завершено (2026-09-12)**
      Spec: `docs/superpowers/specs/2026-09-12-phase3a-scenes-design.md`
      Plan: `docs/superpowers/plans/2026-09-12-phase3a-scenes.md`
* [x] `TtsProviderInterface` + `ElevenLabsTtsProvider`, `GenerateVoiceoverService`,
      `GenerateVoiceoverJob` — **Phase 3b, завершено (2026-09-15)**
      Spec: `docs/superpowers/specs/2026-09-12-phase3b-voiceover-design.md`
      Plan: `docs/superpowers/plans/2026-09-12-phase3b-voiceover.md`
* [x] `MediaAsset` (локальні/stock assets), `AssetProviderInterface` (без прив'язки до
      конкретного stock-провайдера, розділ 12 ТЗ) — **Phase 3c, завершено (2026-09-16)**
      Spec: `docs/superpowers/specs/2026-09-15-phase3c-assets-design.md`
      Plan: `docs/superpowers/plans/2026-09-16-phase3c-assets.md`
* [x] Subtitles: Whisper як Python CLI worker, Laravel отримує structured JSON
      (розділ 11 ТЗ), генерація SRT — **Phase 3d, завершено (2026-09-16)**
      Spec: `docs/superpowers/specs/2026-09-16-phase3d-subtitles-design.md`
      Plan: `docs/superpowers/plans/2026-09-16-phase3d-subtitles.md`
* [x] `VideoRendererInterface` + `FfmpegVideoRenderer` (Symfony Process, без хардкоду
      параметрів — розділ 9–10 ТЗ), configurable vertical template, ASS-стилізація
      субтитрів (шрифт/позиція/розмір/margins з розділу 10 ТЗ) — **Phase 3e,
      завершено (2026-09-17)**
* [x] `RenderVideoJob`, `QualityCheckVideoJob` — **Phase 3e, завершено (2026-09-17)**.
      Контролер-рішення під час брейнштормінгу 3e: замість початково накресленого в
      ROADMAP LLM-based `QualityCheckJob` (purpose=`quality_check` через `LlmManager`)
      побудовано `FfprobeVideoQualityChecker` — детермінований технічний чекер
      (тривалість, роздільна здатність, надмірні чорні кадри) через `ffprobe`, без
      звернення до LLM.
* [x] Feature-тести: scene generation (готово в 3a), voiceover (готово в 3b), asset
      collection (готово в 3c), subtitle generation (готово в 3d), rendering pipeline
      (мокнутий FFmpeg через `Process::fake()`) — **Phase 3e, завершено (2026-09-17)**
      Spec: `docs/superpowers/specs/2026-09-17-phase3e-rendering-design.md`
      Plan: `docs/superpowers/plans/2026-09-17-phase3e-rendering.md`

DoD: пункти 5–9 DoD (розділ 24 ТЗ) — Voiceover, Video Scenes, subtitles, rendering,
готовий `.mp4`, перегляд у Filament. **3a закриває частину пункту 6** (Video Scenes),
**3b закриває пункт 5** (Voiceover), **3c просуває пункт 6 далі** (сцени тепер мають
`asset_id`), **3d закриває пункт 7** (subtitles, у форматі SRT — ASS зі стилізацією
залишено 3e), **3e закриває пункти 8–9** (`FfmpegVideoRenderer` рендерить готовий
`1080x1920.mp4` з ASS-субтитрами, `RenderVideoJob`/`QualityCheckVideoJob` і дії
"Render Video"/"Check Quality" видно в Filament admin panel на `VideosTable`).
**Перевірено** — 176/176 тестів, `pint`, `route:list`, `migrate:fresh --seed`.
Phase 3 (усі під-фази 3a–3e) закрито.

**Досі відкрито, без конкретної наступної фази (з фінального review Phase 3a; Assets
переїхав з 3b у 3c під час брейнштормінгу 3b, але 3c не торкався
`GenerateScenesService`, тож ці пункти лишаються не закритими):**
* `GenerateScenesService`'s LLM-промпт не передає project-контекст (niche/language/
  tone/style), на відміну від `GenerateScriptService` — і зокрема не задає мову для
  `visual_query`, який Phase 3c використовує в пошуку локальних асетів за тегами;
  неанглійська мова проєкту ймовірно дасть неанглійські search-запити, які не
  збігатимуться з англомовними тегами бібліотеки (або навпаки).
* `purpose='script'` (свідомо перевикористаний у 3a для генерації сцен) робить
  script-генерацію і scene-генерацію нерозрізненими в `LlmUsageLog` — для Phase 5
  аналітики варто додати дискримінатор (напр. `metadata['step']`) до накопичення
  об'єму даних.
* `visual_query`-валідація в `GenerateScenesService::parse()` використовує
  `strlen()` (байти), а не `mb_strlen()` (символи) — надто строго для
  багатобайтового UTF-8 тексту; не баг (ніколи не пропускає завелике значення в БД),
  але вартує одного рядка на заміну, коли хтось торкнеться цієї валідації.

**Для Phase 3e — врахувати (з фінального review Phase 3c, ще не закрито в 3d):**
* `MediaAssetsTable` (`app/Filament/Resources/MediaAssets/Tables/
  MediaAssetsTable.php`) не показує колонку тегів — адмін не бачить, які теги вже
  проставлені, не відкриваючи кожен запис окремо; природне місце закрити це разом із
  рештою Filament-полірування у фінальній під-фазі Phase 3.
* `AssetNotFoundException` — детермінована помилка (бібліотека не має відповідного
  asset'а), але `CollectVideoAssetsJob` все одно ретраїть 3 рази з backoff — той
  самий підхід, що вже є в `GenerateScenesJob`/`GenerateVoiceoverJob`/
  `GenerateSubtitlesJob` (детерміновані помилки не виокремлені від транзієнтних).
  Четвертий приклад цього самого гепу — варто закрити одним пакетним фіксом для всіх
  jobs, а не точково.
* Permanent job failure (усі jobs пайплайна) і далі не дає користувачу видимого
  сигналу окрім логу — той самий, вже занотований з Phase 2, гап; `Notification::make()
  ->sendToDatabase()` закрив би це для всього пайплайна разом.

**Для Phase 3e — врахувати (з фінального review Phase 3d):**
* **Архітектурний гап, критичний для 3e**: `docker-compose.yml` має два незалежні
  споживачі однієї Redis-черги `default` — `worker` (свій `docker/worker/Dockerfile`
  з ffmpeg/Python/faster-whisper) і `horizon` (`docker/php/Dockerfile`, без цих
  залежностей). У 3d це вже спричинило реальний баг (`GenerateSubtitlesJob` міг
  дістатись `horizon` і впасти з "python3: not found") — закрито точково через
  виділену чергу `whisper`, яку слухає лише `worker`
  (`app/Jobs/GenerateSubtitlesJob.php`'s `onQueue('whisper')`,
  `docker/worker/Dockerfile`'s `--queue=whisper,default`). **`FfmpegVideoRenderer`
  матиме той самий бінарний-залежний профіль** (ffmpeg вже є лише в `worker`) — або
  дати `RenderVideoJob` свою чергу за тим самим патерном, або нарешті вирішити
  архітектурне дублювання worker/horizon одним махом замість точкового патчингу
  для кожної нової фази.
* `Redis` `retry_after` (90с за замовчуванням, `.env.example` тепер піднято до 700с
  через `REDIS_QUEUE_RETRY_AFTER`) має лишатись вищим за найдовший job timeout у
  пайплайні — `RenderVideoJob`'s FFmpeg-виклик, ймовірно, буде довшим за
  `GenerateSubtitlesJob`'s 650с; варто перевірити це співвідношення, коли з'явиться
  реальний timeout рендерингу.
* `WhisperCliTranscriptionProvider::transcribe()` не перевіряє наявність ключів
  `start`/`end`/`text` у сегменті — malformed-but-valid JSON кине undefined-array-key
  `Error` замість `RuntimeException`; дрібниця, не викликана жодним обов'язковим
  сценарієм, але варта одного рядка захисту, якщо колись торкнешся цього файлу.
* Відео без жодного мовленнєвого сегмента (тиша/музика) сьогодні "успішно" отримує
  нульовий `.srt`-файл і `Video.subtitle_id`, після чого кнопка "Generate Subtitles"
  ховається назавжди — відновлення можливе лише вручну через БД. Свідомо не
  закрито в 3d (це легітимний випадок, не завжди помилка) — але `RenderVideoJob`
  доведеться явно обробити порожній/відсутній subtitle-трек при burn-in, тож варто
  вирішити цей edge case саме там, а не вважати його вже закритим.

**Для Phase 6+ — врахувати (з фінального review Phase 3c, поза межами MVP-скоупу):**
* `LocalAssetProvider::search()` не має `LIMIT` у запиті — скорує в PHP після
  повного `->get()` по типу; прийнятно для MVP-обсягу локальної бібліотеки, але
  варто мати на увазі поруч із реальними stock-провайдерами (Postgres `jsonb` GIN
  індекс на `metadata->'tags'` з `?|` — природний апгрейд, без зміни інтерфейсу).
* `MediaAssetForm`'s `TagsInput::make('metadata.tags')` перезаписує весь JSON
  `metadata` при кожному save (Filament-форма "знає" лише про шлях `metadata.tags` і
  обрізає решту) — сьогодні нешкідливо (нічого іншого не пише в цю колонку), але
  стане реальною втратою даних, коли Phase 6 stock-провайдер почне зберігати
  джерело/атрибуцію в тому самому `metadata`.

**Для Phase 3e — врахувати (з фінального review Phase 3a):**
* `VideoResource`'s форма (`app/Filament/Resources/Videos/Schemas/VideoForm.php`) не
  має `->unique(ignoreRecord: true)` на `script_id` — після unique-індексу з 3a
  дубльоване ручне створення `Video` через адмін-форму падає сирим 500 замість
  валідаційного повідомлення. Той самий момент, коли `VideoResource`/
  `VideoSceneResource` стануть view-only (за аналогією зі `ScriptResource` у Phase 2)
  — природне місце це закрити.
* Після кліку "Generate Scenes" рядок `Script` не дає негайного відгуку (кнопка не
  ховається/не змінюється до завершення job) — косметична незручність, природно
  закривається разом із загальним pipeline-статусом у 3e.

**Для Phase 4+ — врахувати (з фінального review Phase 3e):**
* **П'яте повторення того самого гепу** (вперше зазначено з Phase 2, підтверджено в
  3c для `CollectVideoAssetsJob`): jobs пайплайна не розрізняють детерміновані
  (permanent) і транзієнтні помилки, і permanent failure не скидає `Video.status`
  назад. `RenderVideoJob` після вичерпання retries лишає `Video.status` заклякнутим
  на `Rendering` назавжди — той самий патерн, що вже є в `GenerateVoiceoverJob`/
  `CollectVideoAssetsJob`/`GenerateSubtitlesJob`. П'ять окремих jobs з ідентичним
  недоліком — це вже явний сигнал закрити одним пакетним фіксом (напр. спільний
  `failed()`-хук у базовому Job-класі, що скидає статус і викликає
  `Notification::make()->sendToDatabase()`), а не точково per-job у Phase 4+.
* `FfmpegVideoRenderer::render()` (`app/Domain/Video/Providers/FfmpegVideoRenderer.php`)
  вантажить увесь відрендерений файл у пам'ять через `file_get_contents()` перед
  `Storage::put()` — прийнятно для MVP-масштабу коротких вертикальних відео, але
  варто перейти на `putStream()`, якщо розмір рендерів зросте.
* `FfprobeVideoQualityChecker::probe()`
  (`app/Domain/Video/Providers/FfprobeVideoQualityChecker.php`) мовчки ковтає
  падіння `ffprobe` (не кидає виняток на ненульовий exit-код), на відміну від
  `FfmpegVideoRenderer::probe()` — прийнятно для чекера (крах = провалені checks),
  але вартий позначки для triage, якщо колись знадобиться розрізняти "не пройшло
  перевірку" від "перевірка не змогла запуститись".
* Архітектурне дублювання `worker`/`horizon` (Docker), вперше зазначене з Phase 3d,
  і далі відкрите: `RenderVideoJob`/`QualityCheckVideoJob` використали той самий
  патерн виділеної черги (`render` на `worker`), що й `whisper` — свідоме рішення
  користувача під час брейнштормінгу 3e (не проґавлений момент), але сам дублікат
  контейнерів (`docker/worker/Dockerfile` vs `docker/php/Dockerfile`) лишається
  невирішеним і накопичується з кожною новою чергою.
* `AssSubtitleFormatter::timestamp()` (Task 4, `app/Domain/Video/Support/
  AssSubtitleFormatter.php`) — округлення до сотих секунди може дати "100" без
  перенесення розряду для дробової частини секунди ≥ 0.995; латентно в коді, зданому
  в самому брифі, реальний вплив залежить від того, чи Whisper (3d) коли-небудь
  віддасть не круглі значення часу сегмента.
* `FfprobeVideoQualityChecker::hasExcessiveBlackFrames()` — назва обіцяє більше, ніж
  перевіряє (падає на будь-якому чорному сегменті ≥1с, включно з навмисними
  fade-out) — косметичний момент у найменуванні, поведінка відповідає брифу.
* **Не перевірено на реальному відео**: точна структура xfade-фільтра в
  `FfmpegVideoRenderer` (multi-scene конкатенація з переходами) верифікована лише
  проти `Process::fake()` у тестах — жодного реального прогону `ffmpeg` на
  multi-scene відео в рамках 3e не було. Варто підтвердити на реальних даних перед
  тим, як покладатись на цей шлях у production.

---

## Phase 4 — Publishing ✅ завершено (2026-09-18)

Мета: черга публікацій, ідемпотентність, multi-platform caption/hashtags.

Deliverables:

* [x] `SocialPublisherInterface` + `FakeSocialPublisher`
      (`app/Domain/Publishing/SocialPublisherInterface.php`,
      `app/Domain/Publishing/Providers/FakeSocialPublisher.php`), зареєстрований у
      `PublishingServiceProvider`
* [x] `SocialAccount`, `Publication` CRUD у Filament — `SocialAccountForm` з
      password-masked/`revealable()` `access_token`/`refresh_token`, `PublicationForm`
      з реактивним `video_id → social_account_id` select, caption/hashtags,
      derived-status відображенням, `PublicationsTable` з фільтрами і дією
      "Generate Captions" (`app/Filament/Resources/SocialAccounts/`,
      `app/Filament/Resources/Publications/`). Calendar view з дизайн-спеку
      свідомо не реалізовано — `filament-fullcalendar` вимагав beta-залежності
      (рішення зафіксовано в
      `docs/superpowers/specs/2026-09-17-phase4-publishing-design.md` ще до старту
      Task 1), список/фільтри `PublicationsTable` закривають ту саму потребу
      перегляду розкладу публікацій.
* [x] `PublishVideoJob`: ідемпотентний (`ShouldBeUnique` по `publication_id` +
      status-guard `status !== Scheduled` на вході в `handle()`) —
      `app/Jobs/PublishVideoJob.php`
* [x] Laravel Scheduler: `publications:dispatch-due` command
      (`app/Console/Commands/DispatchDuePublicationsCommand.php`, вибирає
      `status=Scheduled AND scheduled_at<=now()`), зареєстрований у
      `routes/console.php` на щохвилинний тик (розділ 16 ТЗ)
* [x] Генерація caption/hashtags per platform — `GenerateCaptionsService` (purpose=
      `captions` через `LlmManager`, retry/repair на invalid JSON) +
      `GenerateCaptionsJob`, викликається з `PublicationsTable`'s "Generate Captions"
      — прив'язані до конкретного `Publication`/`SocialAccount`, а не до `Script`/
      `ContentIdea`, тож один `Script` може мати різні captions для TikTok/YouTube/
      Instagram/X (розділ 27 ТЗ)
* [x] Feature-тести: publication creation (`PublicationTest`), ідемпотентність
      (`PublishVideoJobTest`'s "calling handle twice does not publish twice"),
      failed jobs (`PublishVideoJobTest`'s "failed marks the publication failed and
      sends a notification"), plus caption generation (`GenerateCaptionsServiceTest`,
      `GenerateCaptionsJobTest`) і database-notifications інфраструктура
      (`NotifiesOnPermanentFailure`, тепер підключена і до 4 video-pipeline jobs, які
      цього чекали з Phase 2/3e review)

DoD: пункти 11–16 DoD (розділ 24 ТЗ) — Publication, scheduled_at, автоматичний запуск
через Queue, `FakePublisher` → `published`, повторний запуск без дублю, помилки видно в
admin panel. **Перевірено** — 205/205 тестів, `pint` (auto-fixed 5 файлів: import order
у `GenerateScenesJob`/`GenerateScriptJob`/`RenderVideoJob`, brace-style у двох
тестах — жодних логічних змін, тести перепрогнано після фіксу, ще раз 205/205),
`route:list` (`admin/social-accounts`, `admin/publications` присутні, 68 маршрутів,
без помилок), `migrate:fresh --seed` (усі 20 міграцій, включно з
`publications.caption`/`hashtags` і `notifications`, застосувались чисто, сідер
відпрацював). Усі 6 DoD-сценаріїв (пп. 11–16) вручну пройдено через
`php artisan tinker`:
1. `Publication::create()` для існуючих `Video`/`SocialAccount` — створено успішно
   (DoD 11).
2. `scheduled_at` у майбутнє + застосування `AppliesScheduledStatus`-логіки → `status`
   стає `Scheduled` (DoD 12).
3. `scheduled_at` у минуле, `publications:dispatch-due` під `Queue::fake()` →
   `PublishVideoJob` запушено з правильним `publicationId` (DoD 13).
4. `PublishVideoJob::dispatchSync()` з `FakeSocialPublisher` → `status=Published`,
   `external_post_id` і `published_at` заповнені (DoD 14).
5. Повторний виклик `dispatch-due` на вже `Published`-записі не пушить job вдруге;
   прямий повторний виклик `handle()` на тому самому job — guard
   (`status !== Scheduled`) no-op, жодне поле не змінюється (DoD 15).
6. Прив'язано throwing-реалізацію `SocialPublisherInterface`, викликано
   `handle()` (кинуло виняток, `status` завис на `Publishing` — та сама відома
   стрендинг-поведінка, що й у `RenderVideoJob`, див. нижче), потім вручну викликано
   `$job->failed($exception)` (симулюючи вичерпання retries воркером) →
   `status=Failed`, у таблиці `notifications` з'явився рядок
   `PipelineJobFailedNotification` з `publication_id`/error у `data`.

Spec: `docs/superpowers/specs/2026-09-17-phase4-publishing-design.md`
Plan: `docs/superpowers/plans/2026-09-17-phase4-publishing.md`

**Для Phase 5+ — врахувати (з фінальних review Tasks 1–16):**
* `PublishVideoJob::handle()` (`app/Jobs/PublishVideoJob.php`) ставить
  `status=Publishing` до фолібельного виклику `publisher->publish()`; якщо той
  кидає виняток, Laravel ретраїть за `backoff()`, але повторна спроба бачить
  `status=Publishing` (не `Scheduled`), guard no-op'ає без винятку, тож `failed()`
  ніколи не викликається і `Publication` назавжди застряє в `Publishing` без
  переходу в `Failed`/нотифікації. Той самий, вже відомий з Phase 3e патерн
  (`RenderVideoJob::handle()`) — Phase 4 його теж не закрив. Підтверджено вручну під
  час DoD-верифікації (сценарій 6 вище).
* `NotifiesOnPermanentFailure` (`app/Jobs/Concerns/NotifiesOnPermanentFailure.php`)
  не має throttling/dedup — кожен permanent failure нотифікує `User::all()` без
  rate limit; retry storm по кількох jobs може заспамити всіх адмінів дублікатами
  database-нотифікацій.
* `GenerateCaptionsServiceTest`
  (`tests/Unit/Domain/Publishing/GenerateCaptionsServiceTest.php`) не покриває
  mid-loop repair-success шлях і `parse()`'s гілки валідації полів — лише "succeeds
  immediately" і "always invalid → throws", той самий gap, що й у прописаному в
  плані тестовому коді.
* `EditSocialAccount`'s форма попередньо заповнює розшифровані
  `access_token`/`refresh_token` (`app/Filament/Resources/SocialAccounts/Schemas/
  SocialAccountForm.php`) у браузерний payload з `revealable()`-тогглом — прийнятно
  для admin-only доступу, але варто мати на увазі, що edit віддає повний
  розшифрований секрет у форму, на відміну від поширеної альтернативи "лишити
  порожнім, перезаписати лише якщо заповнено".
* `PublicationForm`'s каскад `video_id → social_account_id`
  (`app/Filament/Resources/Publications/Schemas/PublicationForm.php`) не скидає
  застарілий вибір: зміна `video_id` після того, як `social_account_id` вже
  вибрано, не чистить (можливо тепер cross-project) `social_account_id` —
  `afterStateUpdated`-скидання відсутнє, і save-time логіка Task 14 це теж не
  валідує.
* `AppliesScheduledStatus`'s межовий випадок `scheduled_at === now()`
  (`app/Filament/Resources/Publications/Concerns/AppliesScheduledStatus.php`)
  резолвиться в `Draft`, не `Scheduled` — `Carbon::isFuture()` вважає рівно-зараз
  не-майбутнім; латентний edge case, жодним тестом не покритий.
* `PublicationsTable`'s фільтр діапазону `scheduled_at`
  (`app/Filament/Resources/Publications/Tables/PublicationsTable.php`) коректно
  підтримує лише `scheduled_from` або лише `scheduled_until` окремо, але це не
  покрито жодним тестом.
* `PublicationFactory`'s closure для `social_account_id`
  (`database/factories/PublicationFactory.php`) викликає
  `SocialAccount::factory()->create()` напряму, минаючи `Model::factory()->
  recycle()` — не підтримає майбутній виклик `Publication::factory()->
  recycle($account)`. Сьогодні інертно (жоден call site `recycle()` не
  використовує).
* `database/migrations/2026_09_18_090752_create_notifications_table.php` відредаговано
  на місці (`text('data')` → `json('data')`) вже після створення й аплуву в
  попередньому task цього ж плану — знадобилось, коли з'ясувалось, що Filament's
  `DatabaseNotifications`-компонент вимагає нативних Postgres JSON-path запитів,
  яких `text`-колонка не підтримує. Безпечно, бо жодне інше оточення ще не
  прогнало оригінальну міграцію (єдиний, ще не змерджений worktree) — але
  зазначено для обізнаності: редагування історії міграцій на місці не є
  загальнобезпечним патерном (нова міграція була б ним, якби це вже не було
  pre-merge).

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
