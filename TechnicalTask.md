# AI Content Factory — ТЗ на MVP

## 1. Мета

Створити Laravel-додаток для автоматизованого виробництва faceless short-form контенту та його подальшої публікації в TikTok, YouTube Shorts, Instagram Reels та X.

Система повинна дозволяти:

1. знаходити/створювати ідеї контенту;
2. генерувати сценарій через LLM;
3. генерувати озвучку;
4. формувати структуру відео;
5. збирати відео з B-roll / screenshots / screen recordings / generated assets;
6. генерувати subtitles;
7. рендерити фінальний MP4 через FFmpeg;
8. зберігати всі assets та результати;
9. ставити відео в чергу на публікацію;
10. у майбутньому автоматично публікувати на соціальні платформи;
11. збирати статистику та використовувати її для оптимізації наступного контенту.

Головна вимога: архітектура повинна дозволяти додавати нові AI-провайдери, відеогенератори та соціальні мережі без переписування основної бізнес-логіки.

---

# 2. Stack

Основний stack:

* PHP 8.4+
* Laravel 12+
* PostgreSQL
* Redis
* Laravel Queue
* Laravel Horizon
* Laravel Scheduler
* Filament для admin panel
* FFmpeg
* S3-compatible storage для media
* Docker

Python НЕ використовувати як основний backend.

Python дозволено використовувати як окремий worker/script для задач, де Python має суттєву перевагу, наприклад:

* Whisper;
* OpenCV;
* computer vision;
* ML;
* спеціалізована обробка media.

Звичайна orchestration/business logic повинна залишатися в Laravel.

---

# 3. Архітектурний принцип

Laravel є центральним orchestrator.

Приблизна схема:

Laravel
→ PostgreSQL
→ Redis
→ Queue
→ Workers
→ AI APIs
→ FFmpeg
→ Storage
→ Social APIs

Не створювати мікросервісну архітектуру на MVP.

Не використовувати Kubernetes.

Не додавати RabbitMQ без конкретної технічної необхідності.

Не створювати окремий Python API, якщо задача може бути виконана через CLI worker.

---

# 4. Основні доменні сутності

Створити моделі та міграції:

## User

Стандартний Laravel User.

## ContentProject

Проект/канал контенту.

Fields:

* id
* name
* slug
* description
* niche
* language
* target_platforms
* status
* settings JSON
* timestamps

Приклад:

```json
{
    "language": "en",
    "tone": "fast",
    "target_duration": 60,
    "style": "tech_news",
    "ai": {
        "default": {
            "provider": "openai",
            "model": "gpt-4o"
        },
        "script": {
            "provider": "anthropic",
            "model": "claude-opus-4"
        },
        "idea": {
            "provider": "openai",
            "model": "gpt-4o-mini"
        },
        "quality_check": {
            "provider": "anthropic",
            "model": "claude-haiku-4.5"
        },
        "captions": {
            "provider": "openai",
            "model": "gpt-4o-mini"
        }
    }
}
```

`ai` — опціональний блок. Ключі, крім `default`, відповідають "purpose" (див. розділ 6). Якщо purpose не вказаний — використовується `ai.default`, якщо і його немає — глобальний provider/model з `.env`.

---

## ContentIdea

Ідея майбутнього відео.

Fields:

* id
* content_project_id
* title
* topic
* source
* source_url nullable
* source_data JSON nullable
* score nullable
* status
* created_at
* updated_at

Statuses:

```text
new
approved
rejected
processing
used
```

---

## Script

Сценарій.

Fields:

* id
* content_idea_id
* provider
* model
* prompt_version
* content
* hook
* estimated_duration
* metadata JSON
* status
* timestamps

---

## Video

Основна сутність відео.

Fields:

* id
* content_project_id
* content_idea_id
* script_id
* title
* description
* status
* duration
* width
* height
* file_path
* thumbnail_path
* metadata JSON
* error_message nullable
* timestamps

Statuses:

```text
draft
script_generated
voice_generated
assets_ready
rendering
rendered
approved
scheduled
publishing
published
failed
```

---

## VideoScene

Окрема сцена відео.

Fields:

* id
* video_id
* order
* type
* duration
* text
* visual_query nullable
* asset_id nullable
* start_time nullable
* end_time nullable
* metadata JSON

Types:

```text
hook
broll
screen_recording
image
screenshot
text
generated_video
transition
cta
```

Приклад:

```json
{
    "type": "broll",
    "duration": 4,
    "visual_query": "AI server room",
    "text": "AI infrastructure is exploding"
}
```

---

## MediaAsset

Будь-який media asset.

Fields:

* id
* type
* provider
* path
* mime_type
* width
* height
* duration
* metadata JSON
* hash
* timestamps

Types:

```text
video
image
audio
subtitle
screen_recording
thumbnail
```

---

## Voiceover

Fields:

* id
* video_id
* provider
* voice
* text
* file_path
* duration
* metadata JSON
* status
* timestamps

---

## SocialAccount

Підключений social account.

Fields:

* id
* content_project_id
* platform
* external_account_id
* username
* access_token encrypted
* refresh_token encrypted nullable
* token_expires_at nullable
* metadata JSON
* status
* timestamps

Platforms:

```text
tiktok
youtube
instagram
x
```

ВАЖЛИВО:

Access/refresh tokens повинні зберігатися encrypted.

Не логувати tokens.

---

## Publication

Публікація відео.

Fields:

* id
* video_id
* social_account_id
* scheduled_at nullable
* published_at nullable
* external_post_id nullable
* status
* error_message nullable
* metadata JSON
* timestamps

Statuses:

```text
draft
scheduled
publishing
published
failed
```

---

## VideoMetric

Статистика публікації.

Fields:

* id
* publication_id
* views
* likes
* comments
* shares
* saves nullable
* watch_time nullable
* completion_rate nullable
* followers_gained nullable
* metadata JSON
* measured_at
* timestamps

---

## LlmUsageLog

Лог кожного звернення до LLM (будь-якого purpose). Використовується для аналітики
вартості та для вибору моделі в майбутньому (розділ 6.2).

Fields:

* id
* content_project_id nullable
* purpose (script / idea / quality_check / captions / ...)
* provider
* model
* prompt_tokens
* completion_tokens
* cost nullable
* duration_ms
* status (success / failed)
* error_message nullable
* metadata JSON (без секретів)
* created_at

---

# 5. Service Layer

Не розміщувати основну бізнес-логіку в Controllers або Models.

Створити services приблизно такого типу:

```text
app/Domain/Content/
    Services/
        GenerateContentIdeaService.php
        GenerateScriptService.php
        GenerateScenesService.php
        GenerateVoiceoverService.php
        RenderVideoService.php
        PublishVideoService.php
        CollectMetricsService.php
```

Назви можна адаптувати до фактичної архітектури проекту.

---

# 6. Provider interfaces

AI integrations повинні використовувати interfaces.

## 6.1 LLM provider — generic interface

LLM використовується у кількох різних місцях (script generation, content idea generation,
quality check, captions/hashtags — розділ 27), тому `LlmProviderInterface` НЕ повинен мати
окремого методу під кожен use case. Провайдер повинен бути generic completion-клієнтом,
а доменна логіка (промпт, схема відповіді, парсинг у DTO) живе в Service/PromptBuilder
на рівні конкретного use case ("purpose").

```php
interface LlmProviderInterface
{
    public function complete(LlmRequest $request): LlmResponse;
}
```

```php
final class LlmRequest
{
    public function __construct(
        public readonly string $purpose,      // script | idea | quality_check | captions | ...
        public readonly array $messages,
        public readonly ?string $responseSchema = null,
        public readonly ?string $model = null,
        public readonly float $temperature = 0.7,
        public readonly ?int $maxTokens = null,
    ) {}
}
```

```php
final class LlmResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly array $metadata = [],
    ) {}
}
```

Реалізації:

```text
OpenAiLlmProvider
AnthropicLlmProvider
GeminiLlmProvider
FakeLlmProvider (для тестів)
```

## 6.2 Вибір провайдера/моделі (LlmManager)

Мета: зручно перемикати провайдера/модель без зміни бізнес-логіки — глобально, per project
або per purpose.

`LlmManager` (Laravel Manager pattern) резолвить конкретний `LlmProviderInterface` + model
за пріоритетом:

```text
1. явний provider/model, переданий у виклику (override для тестів/адмінки)
2. ContentProject.settings.ai.<purpose>
3. ContentProject.settings.ai.default
4. глобальний default з .env / config/llm.php
```

```php
interface LlmManagerInterface
{
    public function resolve(
        ?ContentProject $project,
        string $purpose,
        ?string $providerOverride = null,
        ?string $modelOverride = null,
    ): ResolvedLlmTarget; // provider instance + model name
}
```

`config/llm.php` містить перелік доступних providers/models, дефолти та (опційно) ціну за
1K токенів — для оцінки вартості генерації.

Кожен виклик LLM повинен логуватись у `LlmUsageLog` (content_project_id nullable, purpose,
provider, model, prompt_tokens, completion_tokens, cost nullable, duration_ms, status,
created_at) — без секретів у metadata. Це дає базу для Analytics (розділ 5, Phase 5) і для
прийняття рішення, яку модель використовувати далі.

Додавання нового LLM-провайдера = нова реалізація `LlmProviderInterface` + запис у
`config/llm.php`. Жодна бізнес-логіка (Services/Jobs) не повинна знати про конкретного
провайдера — тільки про `purpose`.

Так само:

```php
interface TtsProviderInterface
{
    public function generate(
        string $text,
        VoiceSettings $settings
    ): VoiceResult;
}
```

Реалізації:

```text
ElevenLabsTtsProvider
...
```

Для social:

```php
interface SocialPublisherInterface
{
    public function publish(
        Video $video,
        Publication $publication
    ): PublicationResult;
}
```

Реалізації:

```text
TikTokPublisher
YoutubePublisher
InstagramPublisher
XPublisher
```

Не прив'язувати доменну логіку безпосередньо до конкретного API provider.

---

# 7. Pipeline генерації відео

Основний pipeline:

```text
ContentIdea
    ↓
GenerateScriptJob
    ↓
Script
    ↓
GenerateScenesJob
    ↓
VideoScene[]
    ↓
GenerateVoiceoverJob
    ↓
Voiceover
    ↓
CollectAssetsJob
    ↓
MediaAsset[]
    ↓
GenerateSubtitlesJob
    ↓
Subtitle
    ↓
RenderVideoJob
    ↓
FFmpeg
    ↓
Video
    ↓
QualityCheckJob
    ↓
approved
```

Кожен етап повинен бути окремою Queue Job.

---

# 8. Queue Jobs

Створити jobs:

```text
GenerateScriptJob
GenerateScenesJob
GenerateVoiceoverJob
CollectVideoAssetsJob
GenerateSubtitlesJob
RenderVideoJob
QualityCheckVideoJob
PublishVideoJob
CollectVideoMetricsJob
```

Jobs повинні:

* бути idempotent;
* мати retry;
* мати timeout;
* логувати помилки;
* не створювати дублікати при повторному запуску;
* зберігати статус виконання.

Важкі media tasks НЕ виконувати в HTTP request.

---

# 9. FFmpeg

FFmpeg використовується для фінального rendering.

Потрібно створити окремий abstraction:

```php
interface VideoRendererInterface
{
    public function render(Video $video): RenderResult;
}
```

Реалізація:

```text
FfmpegVideoRenderer
```

Не викликати shell-команди хаотично з Controllers.

Для запуску процесів використовувати Symfony Process.

---

# 10. Video Template

MVP повинен мати один базовий vertical template:

```text
1080x1920
9:16
```

Структура:

```text
┌─────────────────────┐
│                     │
│       VIDEO         │
│                     │
│                     │
├─────────────────────┤
│      SUBTITLES      │
│                     │
└─────────────────────┘
```

Параметри повинні бути configurable:

* resolution;
* font;
* subtitle position;
* subtitle size;
* margins;
* music volume;
* voice volume;
* B-roll transition;
* scene duration.

Не hardcode значення у FFmpeg commands.

---

# 11. Subtitles

Система повинна генерувати subtitles із timestamps.

MVP:

```text
voice.mp3
↓
transcription
↓
timestamps
↓
ASS/SRT
↓
FFmpeg
```

Whisper можна реалізувати як Python CLI worker.

Laravel повинен запускати worker та отримувати структурований JSON result.

Наприклад:

```json
{
    "segments": [
        {
            "start": 0.0,
            "end": 2.4,
            "text": "This AI just changed coding"
        }
    ]
}
```

---

# 12. Asset system

Assets повинні бути незалежними від конкретного provider.

Наприклад:

```php
interface AssetProviderInterface
{
    public function search(
        string $query,
        AssetSearchOptions $options
    ): array;
}
```

У майбутньому:

```text
Pexels
Pixabay
Unsplash
AI image generator
AI video generator
```

MVP можна почати з локальних assets.

Не робити залежність від конкретного stock provider на першому етапі.

---

# 13. Content generation

LLM повинен генерувати structured output.

Не просити модель повертати просто plain text.

Приклад:

```json
{
    "title": "...",
    "hook": "...",
    "script": "...",
    "duration": 65,
    "scenes": [
        {
            "type": "hook",
            "duration": 3,
            "visual_query": "...",
            "text": "..."
        }
    ],
    "cta": "..."
}
```

Response повинен проходити validation.

Якщо JSON invalid — retry/repair.

---

# 14. Admin Panel

Використати Filament.

Створити sections:

```text
Dashboard

Content Projects
    Projects
    Ideas
    Scripts
    Videos

Media
    Assets
    Voiceovers

Publishing
    Social Accounts
    Publications
    Calendar

Analytics
    Metrics

System
    Jobs
    Logs
    Settings
```

Dashboard повинен показувати:

```text
Videos generated today
Videos published
Failed jobs
Views
Likes
Comments
Best videos
Top performing topics
```

---

# 15. Social publishing

MVP architecture повинна підтримувати:

```text
TikTok
YouTube
Instagram
X
```

Але НЕ потрібно одразу реалізовувати всі API.

Спочатку створити:

```text
SocialPublisherInterface
```

і fake/local implementation:

```text
FakeSocialPublisher
```

щоб можна було тестувати pipeline без реальних соціальних мереж.

Після цього окремо реалізовувати API integrations.

---

# 16. Publishing Scheduler

Laravel Scheduler повинен регулярно перевіряти:

```text
publications.status = scheduled
AND
scheduled_at <= now()
```

і dispatch:

```text
PublishVideoJob
```

Публікація повинна бути idempotent.

Якщо Job запускається двічі, відео не повинно опублікуватися двічі.

---

# 17. Storage

Використовувати Laravel Filesystem.

Не прив'язувати application code до локального filesystem.

```text
Storage::disk('s3')
```

Структура:

```text
projects/{project}/
    ideas/
    scripts/
    audio/
    assets/
    videos/
    thumbnails/
    subtitles/
```

Для local development дозволити local disk.

Production — S3-compatible storage.

---

# 18. Configuration

API keys тільки через `.env`.

Наприклад:

```env
OPENAI_API_KEY=
ANTHROPIC_API_KEY=

ELEVENLABS_API_KEY=

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=
AWS_BUCKET=
```

Ніяких секретів у Git.

---

# 19. Error handling

Кожен pipeline step повинен мати:

```text
pending
processing
completed
failed
```

При failure:

* зберегти error_message;
* зберегти provider response metadata без секретів;
* retry;
* після максимального retry → failed;
* показати помилку в Filament.

---

# 20. Logging

Використовувати Laravel logging.

Окремі channels:

```text
content
video
publishing
ai
```

Не логувати:

* API keys;
* OAuth tokens;
* повні Authorization headers.

---

# 21. Testing

Обов'язково написати Feature/Unit tests для:

* ContentIdea creation;
* script generation;
* scene generation;
* pipeline state transitions;
* video rendering;
* publication;
* duplicate publication prevention;
* failed jobs;
* provider abstraction.

Для external API використовувати mocks/fakes.

Тести не повинні залежати від реальних API.

---

# 22. Docker

Docker Compose:

```text
app
nginx
postgres
redis
worker
horizon
```

FFmpeg повинен бути доступний worker container.

Python dependencies, якщо вони з'являться, повинні бути ізольовані.

Наприклад:

```text
docker/
    php/
    worker/
    python/
```

На MVP Python container створювати тільки якщо реально потрібен persistent Python worker.

---

# 23. MVP Scope

Перша версія повинна реалізовувати тільки:

```text
1. Laravel application
2. PostgreSQL
3. Redis
4. Horizon
5. Filament
6. Content Projects
7. Content Ideas
8. Script generation через один LLM provider
9. Voice generation через один TTS provider
10. Video Scenes
11. Local/stock assets
12. Subtitle generation
13. FFmpeg rendering
14. Video preview/download
15. Fake Social Publisher
16. Queue pipeline
17. Basic analytics model
```

Не реалізовувати на MVP:

```text
microservices
Kubernetes
RabbitMQ
multi-region
billing
multi-tenant SaaS
AI video generation
складний ML
автоматичне визначення viral content
10+ AI providers
```

---

# 24. Definition of Done

Після реалізації я повинен мати можливість:

1. Створити Content Project.
2. Створити Content Idea.
3. Запустити генерацію.
4. Отримати готовий Script.
5. Отримати Voiceover.
6. Побачити список Video Scenes.
7. Згенерувати subtitles.
8. Запустити rendering.
9. Отримати готовий `1080x1920.mp4`.
10. Переглянути його через Filament.
11. Створити Publication.
12. Поставити її на майбутній час.
13. Queue повинна автоматично виконати pipeline.
14. Fake Publisher повинен змінити Publication на `published`.
15. Повторний запуск не повинен створити duplicate publication.
16. Всі помилки повинні бути видимі в admin panel.

---

# 25. Вимоги до реалізації

Перед написанням коду:

1. Проаналізувати існуючий проект.
2. Запропонувати структуру директорій.
3. Визначити entities/models.
4. Визначити relationships.
5. Визначити jobs.
6. Визначити interfaces.
7. Визначити pipeline.
8. Після цього почати реалізацію.

Не робити великий rewrite без необхідності.

Не додавати залежності без пояснення причини.

Не дублювати бізнес-логіку між Controllers, Jobs та Services.

Використовувати Laravel conventions там, де вони достатні.

Після кожного великого етапу запускати:

```bash
php artisan test
php artisan pint
php artisan route:list
php artisan migrate:fresh --seed
```

та виправляти помилки.

---

# 26. Перший етап реалізації

Не реалізовувати весь проект одним великим commit.

Розбити роботу на фази, кожна — окремий spec + implementation plan перед стартом.
Детальна розбивка, deliverables та Definition of Done для кожної фази — в `ROADMAP.md`
(корінь репозиторію). Високорівнево:

```text
Phase 0 — Bootstrap (repo, Docker skeleton, Laravel install)
Phase 1 — Foundation (Postgres/Redis/Horizon/Filament, Models/Migrations, LlmManager)
Phase 2 — Content (ContentProject/ContentIdea/Script, GenerateScriptJob)
Phase 3 — Video (Video/VideoScene/MediaAsset/Voiceover/Subtitles/FFmpeg, QualityCheckJob)
Phase 4 — Publishing (SocialAccount/Publication, FakePublisher, Scheduler, per-platform captions)
Phase 5 — Analytics (VideoMetric, LLM usage/cost dashboard)
```

Реальні social API інтеграції та додаткові LLM/asset providers — після стабілізації MVP
(Phase 6+, поза межами MVP).

---

# 27. Критична вимога

Система повинна бути спроектована так, щоб у майбутньому один Content Project міг генерувати контент у різних форматах:

```text
TikTok
YouTube Shorts
Instagram Reels
X
```

При цьому один і той самий Script/Idea може мати різні:

```text
Video
Publication
Caption
Hashtags
```

для різних платформ.

Не прив'язувати ContentIdea або Script безпосередньо до TikTok.
