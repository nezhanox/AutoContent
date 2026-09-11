# Phase 1 — Foundation: design spec

Джерело: `TechnicalTask.md` (розділи 4, 5, 6.1, 6.2, 17-21, 25), `ROADMAP.md` (Phase 1).

## Мета

Домен, сховище, admin panel і базова AI-абстракція готові до підключення pipeline у
Phase 2+. Без бізнес-логіки генерації (jobs/services, що реально викликають LLM для
сценарію тощо) — тільки фундамент, на якому вона будуватиметься.

## Скоуп

Входить:

* PostgreSQL, Redis, Horizon, Filament встановлені й підключені (пакети,
  service providers, базова конфігурація).
* Усі 11 доменних моделей + міграції з розділу 4 ТЗ: `ContentProject`, `ContentIdea`,
  `Script`, `Video`, `VideoScene`, `MediaAsset`, `Voiceover`, `SocialAccount`,
  `Publication`, `VideoMetric`, `LlmUsageLog` (`User` вже є зі скелету Laravel).
* Eloquent-зв'язки між ними (виведені з `*_id`-полів розділу 4, не описані в ТЗ явно
  — рішення нижче).
* Factories + `DatabaseSeeder` для всіх моделей, включно з одним admin-користувачем
  для локального доступу до Filament.
* `config/llm.php`, `LlmRequest`/`LlmResponse` DTO, `LlmProviderInterface::complete()`
  (розділ 6.1 ТЗ).
* `LlmManagerInterface`/`LlmManager` з резолвінгом за пріоритетом override → per-purpose
  → per-project default → global default (розділ 6.2 ТЗ), з юніт-тестами на
  `FakeLlmProvider`.
* `OpenAiLlmProvider`, `AnthropicLlmProvider` — реальні реалізації поверх Laravel
  `Http`-фасаду, тестовані через `Http::fake()` (без реальних викликів API).
* Логування кожного LLM-виклику в `LlmUsageLog`.
* Logging channels `content`/`video`/`publishing`/`ai` (розділ 20 ТЗ).
* Encrypted casts на `SocialAccount.access_token`/`refresh_token` (розділ 4 ТЗ,
  "ВАЖЛИВО").
* Filament resources для всіх 11 моделей (read-only рівень достатній per DoD, але
  автозгенеровані resources з формами — це нормально, зайвої шкоди немає).

Не входить (свідомо відкладено):

* Jobs pipeline (`GenerateScriptJob` тощо) — Phase 2+.
* Реальна бізнес-логіка Services з розділу 5 ТЗ, окрім самого `LlmManager` —
  Phase 2+.
* FFmpeg/subtitles/rendering — Phase 3.
* Реальні Social API — Phase 4/6+.
* Повний Filament Dashboard з розділу 14 ТЗ (widgets, метрики) — Phase 5.
* Gemini-провайдер (ТЗ згадує його як приклад у розділі 6, MVP-скоуп розділу 23 вимагає
  тільки "один LLM provider"; два реальних провайдери тут — щоб честно перевірити
  здатність `LlmManager` перемикати — більше не потрібно для Phase 1).

## Рішення (там, де ТЗ не фіксує деталь явно)

### Enum-и

PHP 8.4 backed enums (`string`) через Eloquent `casts()`, тільки для полів, чиї
значення ТЗ явно перелічує:

* `ContentIdeaStatus`: new/approved/rejected/processing/used (розділ 4)
* `VideoStatus`: draft/script_generated/voice_generated/assets_ready/rendering/
  rendered/approved/scheduled/publishing/published/failed (розділ 4)
* `VideoSceneType`: hook/broll/screen_recording/image/screenshot/text/
  generated_video/transition/cta (розділ 4)
* `MediaAssetType`: video/image/audio/subtitle/screen_recording/thumbnail (розділ 4)
* `SocialPlatform`: tiktok/youtube/instagram/x (розділ 4)
* `PublicationStatus`: draft/scheduled/publishing/published/failed (розділ 4)

Для полів, де ТЗ каже лише "status" без переліку значень (`Script.status`,
`Voiceover.status`, `SocialAccount.status`) — залишити звичайну `string`-колонку без
enum-класу. Розділ 19 ТЗ дає загальний патерн pending/processing/completed/failed для
pipeline-кроків, але прив'язувати його зараз до конкретних значень — вигадувати
бізнес-логіку, якої ще нема (jobs з'являються в Phase 2+). Enum для цих полів додасть
той job, що реально керує переходами.

### Зв'язки (Eloquent relationships)

Виведені з `*_id`-полів розділу 4:

* `ContentProject` hasMany `ContentIdea`, `Video`, `SocialAccount`
* `ContentIdea` belongsTo `ContentProject`; hasMany `Script`, `Video`
* `Script` belongsTo `ContentIdea`; hasMany `Video`
* `Video` belongsTo `ContentProject`, `ContentIdea`, `Script`; hasMany `VideoScene`,
  `Publication`; hasOne `Voiceover`
* `VideoScene` belongsTo `Video`; belongsTo `MediaAsset` (nullable, `asset_id`)
* `Voiceover` belongsTo `Video`
* `SocialAccount` belongsTo `ContentProject`; hasMany `Publication`
* `Publication` belongsTo `Video`, `SocialAccount`; hasMany `VideoMetric`
* `VideoMetric` belongsTo `Publication`
* `LlmUsageLog` belongsTo `ContentProject` (nullable)
* `MediaAsset` — без прямого belongsTo, це незалежний пул assets (розділ 12 ТЗ:
  "Assets повинні бути незалежними від конкретного provider")

Каскади: дочірні записи (`ContentIdea`→`ContentProject`, `Script`→`ContentIdea`,
`Video`→усі три батьки, `VideoScene`/`Voiceover`/`Publication`→`Video`,
`VideoMetric`→`Publication`) використовують `cascadeOnDelete()` — видалення проєкту
логічно прибирає весь його контент. `VideoScene.asset_id` та `LlmUsageLog.
content_project_id` — `nullOnDelete()` (asset/проєкт може зникнути, не мусить тягнути
сцену/лог за собою).

### `LlmManager` — реалізація

Не буквально `Illuminate\Support\Manager` (його `driver($name)`-патерн заточений під
резолвінг за єдиним ім'ям, а `resolve()` з розділу 6.2 має 4-рівневий пріоритет
project/purpose/override). Замість цього — окремий сервіс, що:

* тримає реєстр `provider name → LlmProviderInterface` інстансів, зібраних із
  `config/llm.php['providers']` через контейнер;
* реалізує `resolve()` точно за пріоритетом з розділу 6.2;
* додатково надає `complete(?ContentProject $project, string $purpose, array $messages,
  ...): LlmResponse` — зручний entry point, що резолвить провайдера, викликає
  `complete()` і логує виклик у `LlmUsageLog` в одному місці. Це не суперечить
  розділу 6.2 (там описаний `resolve()`, тут — тонка обгортка над ним, потрібна
  Phase 2, щоб `GenerateScriptService` не дублював логування в кожному сервісі).

### LLM HTTP-клієнти

Без сторонніх SDK (`openai-php/client` тощо) — обидва провайдери через Laravel
`Http`-фасад напряму до REST API (`chat/completions` для OpenAI, `messages` для
Anthropic). Причина: один консистентний спосіб тестування (`Http::fake()`, розділ 21
ТЗ — "тести не повинні залежати від реальних API"), повний контроль над
retry/repair невалідного JSON (розділ 13 ТЗ) без боротьби з чужими абстракціями,
і без зайвої залежності без обґрунтування (розділ 25 ТЗ).

### Тестова БД

Тести йдуть проти Postgres (не SQLite) — щоб не розходитись із production (це саме
той розрив, який фінальний review Phase 0 зафіксував як відкладене рішення). Окрема
база `autocontent_testing` на тому самому Postgres-сервісі, створювана одноразовим
init-скриптом Postgres-образу (`docker/postgres/init-test-db.sql`, монтується в
`/docker-entrypoint-initdb.d/`, виконується тільки при першій ініціалізації volume).
`phpunit.xml` перемикається з `sqlite`/`:memory:` на `pgsql`/`autocontent_testing`,
`DB_HOST=127.0.0.1` (тести запускаються з host, як і в Phase 0, поки `docker compose
up -d postgres redis` підняті окремо).

### Filament

`filament/filament` v4, один admin-панель провайдер (`php artisan filament:install
--panels`). Resources генеруються стандартною `make:filament-resource --generate` для
всіх 11 моделей — автоформи/таблиці достатньо для DoD ("read-only достатньо"), без
кастомних actions/widgets (це Phase 5). Групування навігації за розділом 14 ТЗ там, де
воно природно лягає на наявні моделі (Content Projects / Media / Publishing /
Analytics) — `System`-групу (Jobs/Logs) не чіпаємо, бо це не Eloquent-моделі.
`DatabaseSeeder` створює одного admin-користувача для локального доступу (email/пароль
з `.env`-подібних значень чи фіксовані dev-креденшли — це виключно для
`migrate:fresh --seed` в dev-оточенні, ніколи не для production).

### Horizon

`laravel/horizon`, `php artisan horizon:install`, стандартна `config/horizon.php`
(supervisor `default` на `redis`-з'єднанні). Auth gate — стоковий Horizon-дефолт
(доступ дозволено в `local`-середовищі). Це закриває розрив, зафіксований у фінальному
review Phase 0 (`horizon`-контейнер падав через відсутній пакет) — Docker-файли з
Phase 0 нічого не міняють, тепер команда `php artisan horizon` існує.

## Acceptance criteria (DoD, з ROADMAP.md Phase 1)

* У Filament видно всі 11 розділів моделей (read-only рівень достатній).
* Можна створити `ContentProject` із `settings.ai` (розділ 4 ТЗ, приклад JSON) і
  отримати коректний resolved provider/model через юніт-тест на `LlmManager::resolve()`
  (з `FakeLlmProvider`, без реальних API-викликів).
* `php artisan test`, `vendor/bin/pint --test`, `php artisan route:list`,
  `php artisan migrate:fresh --seed` проходять чисто.
* `docker compose up` — усі 6 сервісів (включно з `horizon`) стартують без падінь.
