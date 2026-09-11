# Phase 1 — Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Domain models, storage, admin panel, and the multi-LLM abstraction are all in place and tested, so Phase 2 can build the content-generation pipeline on top without touching schema or provider plumbing.

**Architecture:** Eloquent models live in `app/Models/` (stock Laravel convention — TechnicalTask.md §5 only mandates the `app/Domain/*` layout for Services, never for models). The LLM abstraction (`LlmProviderInterface`, DTOs, `LlmManager`, provider implementations) lives in `app/Domain/Llm/` per §6.1-6.2. Filament auto-discovers resources in `app/Filament/Resources/`.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL 16, Redis 7, `laravel/horizon`, `filament/filament` v4, Laravel `Http` facade (no third-party LLM SDKs).

**Spec:** `docs/superpowers/specs/2026-09-11-phase1-foundation-design.md`

## Global Constraints

- PHP 8.4+, Laravel 12+ (TechnicalTask.md §2).
- No business-logic Jobs/Services beyond `LlmManager` in this phase — the generation
  pipeline (`GenerateScriptJob` etc.) is Phase 2+ (spec, Скоуп).
- Models in `app/Models/`, their backed enums in `app/Models/Enums/`. LLM interfaces/DTOs/
  manager/providers in `app/Domain/Llm/` (spec, Рішення — LLM section).
- Backed `string` enums only for fields TechnicalTask.md §4 explicitly enumerates:
  `ContentIdeaStatus`, `VideoStatus`, `VideoSceneType`, `MediaAssetType`,
  `SocialPlatform`, `PublicationStatus`. `Script.status`/`Voiceover.status`/
  `SocialAccount.status` stay plain `string` columns (spec, Рішення — Enum-и).
- **Ruling — pipeline-populated fields get `nullable()` beyond TechnicalTask.md §4's
  literal markers**, because the row is created before the job that fills them exists
  until Phase 2/3: `Script.content`, `Script.hook`, `Script.estimated_duration`;
  `Video.file_path`, `Video.thumbnail_path`, `Video.duration`, `Video.width`,
  `Video.height`; `Voiceover.file_path`, `Voiceover.duration`; `MediaAsset.duration`,
  `MediaAsset.width`, `MediaAsset.height` (not every asset `type` has all three);
  `ContentProject.description`. Every other field's nullability follows TechnicalTask.md
  §4's explicit `nullable` markers exactly.
- Cascade deletes: child rows cascade when their parent is deleted
  (`ContentIdea`→`ContentProject`, `Script`→`ContentIdea`, `Video`→(`ContentProject`,
  `ContentIdea`, `Script`), `VideoScene`/`Voiceover`/`Publication`→`Video`,
  `VideoMetric`→`Publication`). `VideoScene.asset_id` and
  `LlmUsageLog.content_project_id` are `nullOnDelete()` (spec, Рішення — Зв'язки).
- `SocialAccount.access_token`/`refresh_token` use Laravel's native `'encrypted'` cast —
  no separate encryption package (TechnicalTask.md §4 "ВАЖЛИВО").
- LLM providers use the `Http` facade against each provider's REST API directly — no
  `openai-php/client` or similar SDK dependency (spec, Рішення — LLM HTTP-клієнти).
  Provider tests use `Http::fake()` exclusively — never a real network call
  (TechnicalTask.md §21).
- Tests run against Postgres, database `autocontent_testing`, not SQLite (spec,
  Рішення — Тестова БД).
- No secrets logged (TechnicalTask.md §20) — `LlmUsageLog.metadata` must never contain
  API keys or raw Authorization headers.

---

### Task 1: Postgres/Redis/Horizon/Filament wiring + logging channels + Postgres test DB

**Files:**
- Modify: `composer.json`, `composer.lock` (via `composer require`)
- Create: `config/horizon.php` (via `horizon:install`)
- Create: `app/Providers/HorizonServiceProvider.php` (via `horizon:install`)
- Create: `app/Providers/Filament/AdminPanelProvider.php` (via `filament:install --panels`)
- Create: `docker/postgres/init-test-db.sql`
- Modify: `docker-compose.yml` (mount the init script into the `postgres` service)
- Modify: `phpunit.xml` (switch `DB_CONNECTION`/`DB_DATABASE`/`DB_HOST` to Postgres)
- Modify: `config/logging.php` (add `content`/`video`/`publishing`/`ai` channels)
- Test: `tests/Feature/HealthCheckTest.php` (pins the Phase 0 `/up` DoD criterion
  permanently, per the Phase 0 final-review recommendation)

**Interfaces:**
- Consumes: nothing beyond the Phase 0 Laravel/Docker skeleton.
- Produces: a working Postgres connection for every later task's tests (database
  `autocontent_testing`); `laravel/horizon` and `filament/filament` installed so the
  `horizon` and `app` Docker services (already defined in `docker-compose.yml` since
  Phase 0) actually boot their commands; logging channels `content`/`video`/
  `publishing`/`ai` that later tasks/phases write to via `Log::channel('ai')->...` etc.

- [ ] **Step 1: Install Horizon and Filament**

```bash
composer require laravel/horizon:^5.0 filament/filament:^4.0
php artisan horizon:install
php artisan filament:install --panels
```

When `filament:install --panels` prompts for a panel ID, accept the default `admin`.

- [ ] **Step 2: Point Postgres and Redis at Docker service names**

Confirm `config/database.php`'s default connection is `pgsql` (Laravel's stock config
already reads `DB_CONNECTION` from env, and `.env.example` from Phase 0 already sets
`DB_CONNECTION=pgsql`, `DB_HOST=postgres` for in-container use) — no code change needed
here, this step is verification only. Confirm `config/horizon.php`'s default
`use` connection is `redis` (Horizon's installer sets this automatically) — verification
only, no edit unless the installer produced something inconsistent with `REDIS_HOST` in
`.env.example`.

- [ ] **Step 3: Add a Postgres test-database init script**

Create `docker/postgres/init-test-db.sql`:

```sql
CREATE DATABASE autocontent_testing;
```

Edit `docker-compose.yml`'s `postgres` service to mount it (add to the existing
`volumes:` list under `postgres`, alongside `postgres_data:/var/lib/postgresql/data`):

```yaml
      - ./docker/postgres/init-test-db.sql:/docker-entrypoint-initdb.d/init-test-db.sql
```

This SQL only runs the first time the `postgres_data` volume initializes. If a
same-named volume already exists locally from earlier testing, `docker compose down -v`
before bringing the stack up removes it so the init script runs — do this now to get a
clean baseline (there should be no such volume under the `autocontent` project name yet,
since Phase 0's Docker testing ran inside a `phase0-bootstrap`-named worktree, a
different Compose project name — but check with `docker volume ls | grep postgres_data`
and remove any pre-existing `autocontent_postgres_data` volume with
`docker volume rm` before first `up` if one is found).

- [ ] **Step 4: Switch `phpunit.xml` to Postgres**

Edit `phpunit.xml`'s `<php>` block: change

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

to

```xml
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_HOST" value="127.0.0.1"/>
<env name="DB_PORT" value="5432"/>
<env name="DB_DATABASE" value="autocontent_testing"/>
<env name="DB_USERNAME" value="autocontent"/>
<env name="DB_PASSWORD" value="autocontent"/>
```

(matches `.env.example`'s `DB_USERNAME`/`DB_PASSWORD` from Phase 0, and the host-published
port `5432` from `docker-compose.yml`.)

- [ ] **Step 5: Add the `content`/`video`/`publishing`/`ai` logging channels**

Edit `config/logging.php`'s `'channels'` array, add four entries alongside the existing
`'stack'`/`'single'`/etc.:

```php
'content' => [
    'driver' => 'daily',
    'path' => storage_path('logs/content.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'days' => 14,
],

'video' => [
    'driver' => 'daily',
    'path' => storage_path('logs/video.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'days' => 14,
],

'publishing' => [
    'driver' => 'daily',
    'path' => storage_path('logs/publishing.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'days' => 14,
],

'ai' => [
    'driver' => 'daily',
    'path' => storage_path('logs/ai.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'days' => 14,
],
```

- [ ] **Step 6: Bring up Postgres/Redis and verify migrations run against the test DB**

```bash
cd /path/to/repo
docker compose up -d postgres redis
sleep 5
cp .env.example .env
php artisan key:generate
composer install
php artisan migrate --database=pgsql
```

(This uses the dev database `autocontent`, just to confirm connectivity — Step 7's
test run is what actually exercises `autocontent_testing`.)

- [ ] **Step 7: Write the health-check regression test**

Create `tests/Feature/HealthCheckTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_check_returns_200(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }
}
```

- [ ] **Step 8: Run the full suite against Postgres**

```bash
php artisan test
vendor/bin/pint --test
```

Expected: all tests pass (stock `ExampleTest` x2 + the new `HealthCheckTest`), Pint
clean. If `autocontent_testing` doesn't exist yet (init script didn't run because the
volume pre-existed), create it manually as a one-time fix:
`docker compose exec postgres psql -U autocontent -d autocontent -c "CREATE DATABASE autocontent_testing;"`
and note this deviation in your report.

- [ ] **Step 9: Verify Horizon and Filament are reachable**

```bash
php artisan route:list | grep -i horizon
php artisan route:list | grep -i admin
```

Expected: Horizon and Filament admin routes are listed (concrete proof the packages
installed correctly; don't worry about visually loading them in a browser here — a
later task's Filament resources will exercise the panel).

- [ ] **Step 10: Verify the `horizon` Docker service now stays up**

Phase 0's final review flagged that the `horizon` Compose service (already defined in
`docker-compose.yml`) exited with `Command "horizon" is not defined` because the package
wasn't installed yet. Confirm that's fixed now:

```bash
docker compose up -d --build
sleep 8
docker compose ps
```

Expected: all 6 services (`app`, `nginx`, `postgres`, `redis`, `worker`, `horizon`) show
`Up`/`running` — `horizon` in particular must NOT be `Exited`. Check
`docker compose logs horizon` if it did exit, and fix the root cause (a missing
`vendor/laravel/horizon` in the image — likely because the image was built before this
task's `composer require`, in which case `docker compose up -d --build` above already
handles it by rebuilding; if it still fails, diagnose from the log output). Then:

```bash
docker compose down
```

- [ ] **Step 11: Clean up and commit**

```bash
docker compose down
rm -f .env
git status
git add -A
git commit -m "Install Postgres/Redis/Horizon/Filament wiring, logging channels, Postgres test DB"
```

---

### Task 2: Content domain — ContentProject, ContentIdea, Script

**Files:**
- Create: `database/migrations/<timestamp>_create_content_projects_table.php`
- Create: `database/migrations/<timestamp>_create_content_ideas_table.php`
- Create: `database/migrations/<timestamp>_create_scripts_table.php`
- Create: `app/Models/ContentProject.php`
- Create: `app/Models/ContentIdea.php`
- Create: `app/Models/Script.php`
- Create: `app/Models/Enums/ContentIdeaStatus.php`
- Create: `database/factories/ContentProjectFactory.php`
- Create: `database/factories/ContentIdeaFactory.php`
- Create: `database/factories/ScriptFactory.php`
- Test: `tests/Feature/ContentDomainModelsTest.php`

**Interfaces:**
- Consumes: Postgres test DB from Task 1.
- Produces: `App\Models\ContentProject` (fields: `id, name, slug, description?, niche,
  language, target_platforms (array cast), status, settings (array cast), timestamps`),
  `App\Models\ContentIdea` (fields: `id, content_project_id, title, topic, source,
  source_url?, source_data? (array cast), score?, status (ContentIdeaStatus cast),
  timestamps`), `App\Models\Script` (fields: `id, content_idea_id, provider, model,
  prompt_version, content?, hook?, estimated_duration?, metadata (array cast), status,
  timestamps`) — later tasks (Task 3's `Video`, Task 6-9's `LlmManager`/providers) will
  `belongsTo`/reference these by these exact class and field names.

- [ ] **Step 1: `ContentIdeaStatus` enum**

Create `app/Models/Enums/ContentIdeaStatus.php`:

```php
<?php

namespace App\Models\Enums;

enum ContentIdeaStatus: string
{
    case New = 'new';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Processing = 'processing';
    case Used = 'used';
}
```

- [ ] **Step 2: Migrations**

```bash
php artisan make:migration create_content_projects_table
php artisan make:migration create_content_ideas_table
php artisan make:migration create_scripts_table
```

`create_content_projects_table`:

```php
Schema::create('content_projects', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->text('description')->nullable();
    $table->string('niche');
    $table->string('language');
    $table->json('target_platforms')->default('[]');
    $table->string('status');
    $table->json('settings')->default('{}');
    $table->timestamps();
});
```

`create_content_ideas_table`:

```php
Schema::create('content_ideas', function (Blueprint $table) {
    $table->id();
    $table->foreignId('content_project_id')->constrained()->cascadeOnDelete();
    $table->string('title');
    $table->string('topic');
    $table->string('source');
    $table->string('source_url')->nullable();
    $table->json('source_data')->nullable();
    $table->float('score')->nullable();
    $table->string('status');
    $table->timestamps();
});
```

`create_scripts_table`:

```php
Schema::create('scripts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('content_idea_id')->constrained()->cascadeOnDelete();
    $table->string('provider');
    $table->string('model');
    $table->string('prompt_version');
    $table->longText('content')->nullable();
    $table->text('hook')->nullable();
    $table->unsignedInteger('estimated_duration')->nullable();
    $table->json('metadata')->default('{}');
    $table->string('status');
    $table->timestamps();
});
```

- [ ] **Step 3: Models**

`app/Models/ContentProject.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'description', 'niche', 'language',
        'target_platforms', 'status', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'target_platforms' => 'array',
            'settings' => 'array',
        ];
    }

    public function contentIdeas(): HasMany
    {
        return $this->hasMany(ContentIdea::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }
}
```

`app/Models/ContentIdea.php`:

```php
<?php

namespace App\Models;

use App\Models\Enums\ContentIdeaStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentIdea extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_project_id', 'title', 'topic', 'source',
        'source_url', 'source_data', 'score', 'status',
    ];

    protected function casts(): array
    {
        return [
            'source_data' => 'array',
            'status' => ContentIdeaStatus::class,
        ];
    }

    public function contentProject(): BelongsTo
    {
        return $this->belongsTo(ContentProject::class);
    }

    public function scripts(): HasMany
    {
        return $this->hasMany(Script::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }
}
```

`app/Models/Script.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Script extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_idea_id', 'provider', 'model', 'prompt_version',
        'content', 'hook', 'estimated_duration', 'metadata', 'status',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function contentIdea(): BelongsTo
    {
        return $this->belongsTo(ContentIdea::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }
}
```

- [ ] **Step 4: Factories**

`database/factories/ContentProjectFactory.php`:

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ContentProjectFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 100000),
            'description' => fake()->sentence(),
            'niche' => fake()->randomElement(['tech_news', 'fitness', 'finance', 'gaming']),
            'language' => fake()->randomElement(['en', 'uk', 'es']),
            'target_platforms' => fake()->randomElements(['tiktok', 'youtube', 'instagram', 'x'], 2),
            'status' => 'active',
            'settings' => [
                'tone' => 'fast',
                'target_duration' => 60,
                'ai' => [
                    'default' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
                ],
            ],
        ];
    }
}
```

`database/factories/ContentIdeaFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContentIdeaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_project_id' => ContentProject::factory(),
            'title' => fake()->sentence(6),
            'topic' => fake()->words(3, true),
            'source' => fake()->randomElement(['manual', 'trend_scan', 'rss']),
            'source_url' => fake()->optional()->url(),
            'source_data' => null,
            'score' => fake()->optional()->randomFloat(2, 0, 100),
            'status' => ContentIdeaStatus::New,
        ];
    }
}
```

`database/factories/ScriptFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ContentIdea;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScriptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_idea_id' => ContentIdea::factory(),
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'prompt_version' => 'v1',
            'content' => fake()->paragraphs(3, true),
            'hook' => fake()->sentence(),
            'estimated_duration' => fake()->numberBetween(30, 90),
            'metadata' => [],
            'status' => 'completed',
        ];
    }
}
```

- [ ] **Step 5: Write the model test**

Create `tests/Feature/ContentDomainModelsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentDomainModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_project_has_many_content_ideas(): void
    {
        $project = ContentProject::factory()->create();
        $idea = ContentIdea::factory()->create(['content_project_id' => $project->id]);

        $this->assertTrue($project->contentIdeas->contains($idea));
        $this->assertTrue($idea->contentProject->is($project));
    }

    public function test_content_idea_status_casts_to_enum(): void
    {
        $idea = ContentIdea::factory()->create(['status' => ContentIdeaStatus::Approved]);

        $this->assertSame(ContentIdeaStatus::Approved, $idea->fresh()->status);
    }

    public function test_script_belongs_to_content_idea_and_deletes_with_it(): void
    {
        $idea = ContentIdea::factory()->create();
        $script = Script::factory()->create(['content_idea_id' => $idea->id]);

        $this->assertTrue($script->contentIdea->is($idea));

        $idea->delete();

        $this->assertDatabaseMissing('scripts', ['id' => $script->id]);
    }

    public function test_content_project_settings_cast_to_array(): void
    {
        $project = ContentProject::factory()->create();

        $this->assertIsArray($project->fresh()->settings);
        $this->assertArrayHasKey('ai', $project->settings);
    }
}
```

- [ ] **Step 6: Run migrations and tests**

```bash
php artisan migrate:fresh --database=pgsql
php artisan test --filter=ContentDomainModelsTest
vendor/bin/pint --test
```

Expected: all 4 tests pass, Pint clean.

- [ ] **Step 7: Commit**

```bash
git add database/migrations database/factories app/Models tests/Feature/ContentDomainModelsTest.php
git commit -m "Add ContentProject, ContentIdea, Script models with migrations and factories"
```

---

### Task 3: Video domain — Video, VideoScene, MediaAsset, Voiceover

**Files:**
- Create: `database/migrations/<timestamp>_create_media_assets_table.php`
- Create: `database/migrations/<timestamp>_create_videos_table.php`
- Create: `database/migrations/<timestamp>_create_video_scenes_table.php`
- Create: `database/migrations/<timestamp>_create_voiceovers_table.php`
- Create: `app/Models/Video.php`
- Create: `app/Models/VideoScene.php`
- Create: `app/Models/MediaAsset.php`
- Create: `app/Models/Voiceover.php`
- Create: `app/Models/Enums/VideoStatus.php`
- Create: `app/Models/Enums/VideoSceneType.php`
- Create: `app/Models/Enums/MediaAssetType.php`
- Create: `database/factories/VideoFactory.php`
- Create: `database/factories/VideoSceneFactory.php`
- Create: `database/factories/MediaAssetFactory.php`
- Create: `database/factories/VoiceoverFactory.php`
- Test: `tests/Feature/VideoDomainModelsTest.php`

**Interfaces:**
- Consumes: `App\Models\ContentProject`, `App\Models\ContentIdea`, `App\Models\Script`
  from Task 2 (Video `belongsTo` all three).
- Produces: `App\Models\Video` (`id, content_project_id, content_idea_id, script_id,
  title, description, status (VideoStatus cast), duration?, width?, height?, file_path?,
  thumbnail_path?, metadata (array), error_message?, timestamps`), `App\Models\VideoScene`
  (`id, video_id, order, type (VideoSceneType cast), duration, text, visual_query?,
  asset_id?, start_time?, end_time?, metadata (array)`), `App\Models\MediaAsset`
  (`id, type (MediaAssetType cast), provider, path, mime_type, width?, height?,
  duration?, metadata (array), hash, timestamps`), `App\Models\Voiceover`
  (`id, video_id, provider, voice, text, file_path?, duration?, metadata (array),
  status, timestamps`) — Task 4's `Publication` will `belongsTo` `Video`.

- [ ] **Step 1: Enums**

`app/Models/Enums/VideoStatus.php`:

```php
<?php

namespace App\Models\Enums;

enum VideoStatus: string
{
    case Draft = 'draft';
    case ScriptGenerated = 'script_generated';
    case VoiceGenerated = 'voice_generated';
    case AssetsReady = 'assets_ready';
    case Rendering = 'rendering';
    case Rendered = 'rendered';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
}
```

`app/Models/Enums/VideoSceneType.php`:

```php
<?php

namespace App\Models\Enums;

enum VideoSceneType: string
{
    case Hook = 'hook';
    case Broll = 'broll';
    case ScreenRecording = 'screen_recording';
    case Image = 'image';
    case Screenshot = 'screenshot';
    case Text = 'text';
    case GeneratedVideo = 'generated_video';
    case Transition = 'transition';
    case Cta = 'cta';
}
```

`app/Models/Enums/MediaAssetType.php`:

```php
<?php

namespace App\Models\Enums;

enum MediaAssetType: string
{
    case Video = 'video';
    case Image = 'image';
    case Audio = 'audio';
    case Subtitle = 'subtitle';
    case ScreenRecording = 'screen_recording';
    case Thumbnail = 'thumbnail';
}
```

- [ ] **Step 2: Migrations**

```bash
php artisan make:migration create_media_assets_table
php artisan make:migration create_videos_table
php artisan make:migration create_video_scenes_table
php artisan make:migration create_voiceovers_table
```

`create_media_assets_table` (create first — `video_scenes` references it):

```php
Schema::create('media_assets', function (Blueprint $table) {
    $table->id();
    $table->string('type');
    $table->string('provider');
    $table->string('path');
    $table->string('mime_type');
    $table->unsignedInteger('width')->nullable();
    $table->unsignedInteger('height')->nullable();
    $table->unsignedInteger('duration')->nullable();
    $table->json('metadata')->default('{}');
    $table->string('hash');
    $table->timestamps();
});
```

`create_videos_table`:

```php
Schema::create('videos', function (Blueprint $table) {
    $table->id();
    $table->foreignId('content_project_id')->constrained()->cascadeOnDelete();
    $table->foreignId('content_idea_id')->constrained()->cascadeOnDelete();
    $table->foreignId('script_id')->constrained()->cascadeOnDelete();
    $table->string('title');
    $table->text('description');
    $table->string('status');
    $table->unsignedInteger('duration')->nullable();
    $table->unsignedInteger('width')->nullable();
    $table->unsignedInteger('height')->nullable();
    $table->string('file_path')->nullable();
    $table->string('thumbnail_path')->nullable();
    $table->json('metadata')->default('{}');
    $table->text('error_message')->nullable();
    $table->timestamps();
});
```

`create_video_scenes_table`:

```php
Schema::create('video_scenes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('video_id')->constrained()->cascadeOnDelete();
    $table->unsignedInteger('order');
    $table->string('type');
    $table->unsignedInteger('duration');
    $table->text('text');
    $table->string('visual_query')->nullable();
    $table->foreignId('asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
    $table->unsignedInteger('start_time')->nullable();
    $table->unsignedInteger('end_time')->nullable();
    $table->json('metadata')->default('{}');
    $table->timestamps();
});
```

`create_voiceovers_table`:

```php
Schema::create('voiceovers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('video_id')->constrained()->cascadeOnDelete();
    $table->string('provider');
    $table->string('voice');
    $table->text('text');
    $table->string('file_path')->nullable();
    $table->unsignedInteger('duration')->nullable();
    $table->json('metadata')->default('{}');
    $table->string('status');
    $table->timestamps();
});
```

- [ ] **Step 3: Models**

`app/Models/MediaAsset.php`:

```php
<?php

namespace App\Models;

use App\Models\Enums\MediaAssetType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'type', 'provider', 'path', 'mime_type', 'width', 'height',
        'duration', 'metadata', 'hash',
    ];

    protected function casts(): array
    {
        return [
            'type' => MediaAssetType::class,
            'metadata' => 'array',
        ];
    }

    public function videoScenes(): HasMany
    {
        return $this->hasMany(VideoScene::class, 'asset_id');
    }
}
```

`app/Models/Video.php`:

```php
<?php

namespace App\Models;

use App\Models\Enums\VideoStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Video extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_project_id', 'content_idea_id', 'script_id', 'title', 'description',
        'status', 'duration', 'width', 'height', 'file_path', 'thumbnail_path',
        'metadata', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => VideoStatus::class,
            'metadata' => 'array',
        ];
    }

    public function contentProject(): BelongsTo
    {
        return $this->belongsTo(ContentProject::class);
    }

    public function contentIdea(): BelongsTo
    {
        return $this->belongsTo(ContentIdea::class);
    }

    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(VideoScene::class)->orderBy('order');
    }

    public function voiceover(): HasOne
    {
        return $this->hasOne(Voiceover::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }
}
```

`app/Models/VideoScene.php`:

```php
<?php

namespace App\Models;

use App\Models\Enums\VideoSceneType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoScene extends Model
{
    use HasFactory;

    protected $fillable = [
        'video_id', 'order', 'type', 'duration', 'text', 'visual_query',
        'asset_id', 'start_time', 'end_time', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type' => VideoSceneType::class,
            'metadata' => 'array',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'asset_id');
    }
}
```

`app/Models/Voiceover.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voiceover extends Model
{
    use HasFactory;

    protected $fillable = [
        'video_id', 'provider', 'voice', 'text', 'file_path', 'duration',
        'metadata', 'status',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
```

- [ ] **Step 4: Factories**

`database/factories/MediaAssetFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Enums\MediaAssetType;
use Illuminate\Database\Eloquent\Factories\Factory;

class MediaAssetFactory extends Factory
{
    public function definition(): array
    {
        $type = fake()->randomElement(MediaAssetType::cases());

        return [
            'type' => $type,
            'provider' => 'local',
            'path' => 'assets/'.fake()->uuid().'.'.($type === MediaAssetType::Audio ? 'mp3' : 'mp4'),
            'mime_type' => $type === MediaAssetType::Audio ? 'audio/mpeg' : 'video/mp4',
            'width' => in_array($type, [MediaAssetType::Video, MediaAssetType::Image, MediaAssetType::Screenshot, MediaAssetType::ScreenRecording, MediaAssetType::Thumbnail], true) ? 1080 : null,
            'height' => in_array($type, [MediaAssetType::Video, MediaAssetType::Image, MediaAssetType::Screenshot, MediaAssetType::ScreenRecording, MediaAssetType::Thumbnail], true) ? 1920 : null,
            'duration' => in_array($type, [MediaAssetType::Video, MediaAssetType::Audio, MediaAssetType::ScreenRecording], true) ? fake()->numberBetween(3, 30) : null,
            'metadata' => [],
            'hash' => fake()->sha256(),
        ];
    }
}
```

`database/factories/VideoFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\VideoStatus;
use App\Models\Script;
use Illuminate\Database\Eloquent\Factories\Factory;

class VideoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_project_id' => ContentProject::factory(),
            'content_idea_id' => ContentIdea::factory(),
            'script_id' => Script::factory(),
            'title' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'status' => VideoStatus::Draft,
            'duration' => null,
            'width' => null,
            'height' => null,
            'file_path' => null,
            'thumbnail_path' => null,
            'metadata' => [],
            'error_message' => null,
        ];
    }
}
```

`database/factories/VideoSceneFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Enums\VideoSceneType;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

class VideoSceneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'video_id' => Video::factory(),
            'order' => fake()->numberBetween(0, 10),
            'type' => fake()->randomElement(VideoSceneType::cases()),
            'duration' => fake()->numberBetween(2, 6),
            'text' => fake()->sentence(),
            'visual_query' => fake()->optional()->words(3, true),
            'asset_id' => null,
            'start_time' => null,
            'end_time' => null,
            'metadata' => [],
        ];
    }
}
```

`database/factories/VoiceoverFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

class VoiceoverFactory extends Factory
{
    public function definition(): array
    {
        return [
            'video_id' => Video::factory(),
            'provider' => 'elevenlabs',
            'voice' => fake()->randomElement(['adam', 'rachel', 'bella']),
            'text' => fake()->paragraph(),
            'file_path' => null,
            'duration' => null,
            'metadata' => [],
            'status' => 'pending',
        ];
    }
}
```

- [ ] **Step 5: Write the model test**

Create `tests/Feature/VideoDomainModelsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Enums\VideoStatus;
use App\Models\MediaAsset;
use App\Models\Video;
use App\Models\VideoScene;
use App\Models\Voiceover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoDomainModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_belongs_to_project_idea_and_script(): void
    {
        $video = Video::factory()->create();

        $this->assertNotNull($video->contentProject);
        $this->assertNotNull($video->contentIdea);
        $this->assertNotNull($video->script);
        $this->assertSame(VideoStatus::Draft, $video->status);
    }

    public function test_video_has_many_ordered_scenes(): void
    {
        $video = Video::factory()->create();
        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 2]);
        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 1]);

        $this->assertSame([1, 2], $video->scenes->pluck('order')->all());
    }

    public function test_video_has_one_voiceover(): void
    {
        $video = Video::factory()->create();
        $voiceover = Voiceover::factory()->create(['video_id' => $video->id]);

        $this->assertTrue($video->voiceover->is($voiceover));
    }

    public function test_video_scene_can_reference_media_asset_and_survives_asset_deletion(): void
    {
        $asset = MediaAsset::factory()->create();
        $scene = VideoScene::factory()->create(['asset_id' => $asset->id]);

        $asset->delete();

        $this->assertDatabaseHas('video_scenes', ['id' => $scene->id, 'asset_id' => null]);
    }

    public function test_deleting_video_cascades_to_scenes_and_voiceover(): void
    {
        $video = Video::factory()->create();
        $scene = VideoScene::factory()->create(['video_id' => $video->id]);
        $voiceover = Voiceover::factory()->create(['video_id' => $video->id]);

        $video->delete();

        $this->assertDatabaseMissing('video_scenes', ['id' => $scene->id]);
        $this->assertDatabaseMissing('voiceovers', ['id' => $voiceover->id]);
    }
}
```

- [ ] **Step 6: Run migrations and tests**

```bash
php artisan migrate:fresh --database=pgsql
php artisan test --filter=VideoDomainModelsTest
vendor/bin/pint --test
```

Expected: all 5 tests pass, Pint clean.

- [ ] **Step 7: Commit**

```bash
git add database/migrations database/factories app/Models tests/Feature/VideoDomainModelsTest.php
git commit -m "Add Video, VideoScene, MediaAsset, Voiceover models with migrations and factories"
```

---

### Task 4: Publishing + Analytics domain — SocialAccount, Publication, VideoMetric, LlmUsageLog

**Files:**
- Create: `database/migrations/<timestamp>_create_social_accounts_table.php`
- Create: `database/migrations/<timestamp>_create_publications_table.php`
- Create: `database/migrations/<timestamp>_create_video_metrics_table.php`
- Create: `database/migrations/<timestamp>_create_llm_usage_logs_table.php`
- Create: `app/Models/SocialAccount.php`
- Create: `app/Models/Publication.php`
- Create: `app/Models/VideoMetric.php`
- Create: `app/Models/LlmUsageLog.php`
- Create: `app/Models/Enums/SocialPlatform.php`
- Create: `app/Models/Enums/PublicationStatus.php`
- Create: `app/Models/Enums/LlmUsageLogStatus.php`
- Create: `database/factories/SocialAccountFactory.php`
- Create: `database/factories/PublicationFactory.php`
- Create: `database/factories/VideoMetricFactory.php`
- Create: `database/factories/LlmUsageLogFactory.php`
- Test: `tests/Feature/PublishingAnalyticsModelsTest.php`

**Interfaces:**
- Consumes: `App\Models\ContentProject` (Task 2), `App\Models\Video` (Task 3).
- Produces: `App\Models\SocialAccount` (`id, content_project_id, platform
  (SocialPlatform cast), external_account_id, username, access_token (encrypted cast),
  refresh_token? (encrypted cast), token_expires_at?, metadata (array), status,
  timestamps`), `App\Models\Publication` (`id, video_id, social_account_id,
  scheduled_at?, published_at?, external_post_id?, status (PublicationStatus cast),
  error_message?, metadata (array), timestamps`), `App\Models\VideoMetric`
  (`id, publication_id, views, likes, comments, shares, saves?, watch_time?,
  completion_rate?, followers_gained?, metadata (array), measured_at, timestamps`),
  `App\Models\LlmUsageLog` (`id, content_project_id?, purpose, provider, model,
  prompt_tokens, completion_tokens, cost?, duration_ms, status (LlmUsageLogStatus cast),
  error_message?, metadata (array), created_at`) — Task 7's `LlmManager` writes
  `LlmUsageLog` rows using exactly these field names.

- [ ] **Step 1: Enums**

`app/Models/Enums/SocialPlatform.php`:

```php
<?php

namespace App\Models\Enums;

enum SocialPlatform: string
{
    case TikTok = 'tiktok';
    case YouTube = 'youtube';
    case Instagram = 'instagram';
    case X = 'x';
}
```

`app/Models/Enums/PublicationStatus.php`:

```php
<?php

namespace App\Models\Enums;

enum PublicationStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
}
```

`app/Models/Enums/LlmUsageLogStatus.php`:

```php
<?php

namespace App\Models\Enums;

enum LlmUsageLogStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
}
```

- [ ] **Step 2: Migrations**

```bash
php artisan make:migration create_social_accounts_table
php artisan make:migration create_publications_table
php artisan make:migration create_video_metrics_table
php artisan make:migration create_llm_usage_logs_table
```

`create_social_accounts_table`:

```php
Schema::create('social_accounts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('content_project_id')->constrained()->cascadeOnDelete();
    $table->string('platform');
    $table->string('external_account_id');
    $table->string('username');
    $table->text('access_token');
    $table->text('refresh_token')->nullable();
    $table->timestamp('token_expires_at')->nullable();
    $table->json('metadata')->default('{}');
    $table->string('status');
    $table->timestamps();
});
```

`create_publications_table`:

```php
Schema::create('publications', function (Blueprint $table) {
    $table->id();
    $table->foreignId('video_id')->constrained()->cascadeOnDelete();
    $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
    $table->timestamp('scheduled_at')->nullable();
    $table->timestamp('published_at')->nullable();
    $table->string('external_post_id')->nullable();
    $table->string('status');
    $table->text('error_message')->nullable();
    $table->json('metadata')->default('{}');
    $table->timestamps();
});
```

`create_video_metrics_table`:

```php
Schema::create('video_metrics', function (Blueprint $table) {
    $table->id();
    $table->foreignId('publication_id')->constrained()->cascadeOnDelete();
    $table->unsignedBigInteger('views')->default(0);
    $table->unsignedBigInteger('likes')->default(0);
    $table->unsignedBigInteger('comments')->default(0);
    $table->unsignedBigInteger('shares')->default(0);
    $table->unsignedBigInteger('saves')->nullable();
    $table->unsignedInteger('watch_time')->nullable();
    $table->float('completion_rate')->nullable();
    $table->unsignedInteger('followers_gained')->nullable();
    $table->json('metadata')->default('{}');
    $table->timestamp('measured_at');
    $table->timestamps();
});
```

`create_llm_usage_logs_table`:

```php
Schema::create('llm_usage_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('content_project_id')->nullable()->constrained()->nullOnDelete();
    $table->string('purpose');
    $table->string('provider');
    $table->string('model');
    $table->unsignedInteger('prompt_tokens');
    $table->unsignedInteger('completion_tokens');
    $table->decimal('cost', 10, 6)->nullable();
    $table->unsignedInteger('duration_ms');
    $table->string('status');
    $table->text('error_message')->nullable();
    $table->json('metadata')->default('{}');
    $table->timestamp('created_at');
});
```

- [ ] **Step 3: Models**

`app/Models/SocialAccount.php`:

```php
<?php

namespace App\Models;

use App\Models\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_project_id', 'platform', 'external_account_id', 'username',
        'access_token', 'refresh_token', 'token_expires_at', 'metadata', 'status',
    ];

    protected $hidden = [
        'access_token', 'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'platform' => SocialPlatform::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function contentProject(): BelongsTo
    {
        return $this->belongsTo(ContentProject::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }
}
```

`app/Models/Publication.php`:

```php
<?php

namespace App\Models;

use App\Models\Enums\PublicationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Publication extends Model
{
    use HasFactory;

    protected $fillable = [
        'video_id', 'social_account_id', 'scheduled_at', 'published_at',
        'external_post_id', 'status', 'error_message', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'status' => PublicationStatus::class,
            'metadata' => 'array',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function videoMetrics(): HasMany
    {
        return $this->hasMany(VideoMetric::class);
    }
}
```

`app/Models/VideoMetric.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'publication_id', 'views', 'likes', 'comments', 'shares', 'saves',
        'watch_time', 'completion_rate', 'followers_gained', 'metadata', 'measured_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'measured_at' => 'datetime',
        ];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }
}
```

`app/Models/LlmUsageLog.php`:

```php
<?php

namespace App\Models;

use App\Models\Enums\LlmUsageLogStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LlmUsageLog extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'content_project_id', 'purpose', 'provider', 'model', 'prompt_tokens',
        'completion_tokens', 'cost', 'duration_ms', 'status', 'error_message', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => LlmUsageLogStatus::class,
            'metadata' => 'array',
        ];
    }

    public function contentProject(): BelongsTo
    {
        return $this->belongsTo(ContentProject::class);
    }
}
```

- [ ] **Step 4: Factories**

`database/factories/SocialAccountFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ContentProject;
use App\Models\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Factories\Factory;

class SocialAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_project_id' => ContentProject::factory(),
            'platform' => fake()->randomElement(SocialPlatform::cases()),
            'external_account_id' => fake()->uuid(),
            'username' => fake()->userName(),
            'access_token' => fake()->sha256(),
            'refresh_token' => fake()->optional()->sha256(),
            'token_expires_at' => fake()->optional()->dateTimeBetween('now', '+30 days'),
            'metadata' => [],
            'status' => 'active',
        ];
    }
}
```

`database/factories/PublicationFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Enums\PublicationStatus;
use App\Models\SocialAccount;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

class PublicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'video_id' => Video::factory(),
            'social_account_id' => SocialAccount::factory(),
            'scheduled_at' => null,
            'published_at' => null,
            'external_post_id' => null,
            'status' => PublicationStatus::Draft,
            'error_message' => null,
            'metadata' => [],
        ];
    }
}
```

`database/factories/VideoMetricFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Publication;
use Illuminate\Database\Eloquent\Factories\Factory;

class VideoMetricFactory extends Factory
{
    public function definition(): array
    {
        return [
            'publication_id' => Publication::factory(),
            'views' => fake()->numberBetween(0, 100000),
            'likes' => fake()->numberBetween(0, 10000),
            'comments' => fake()->numberBetween(0, 1000),
            'shares' => fake()->numberBetween(0, 500),
            'saves' => fake()->optional()->numberBetween(0, 500),
            'watch_time' => fake()->optional()->numberBetween(1, 60),
            'completion_rate' => fake()->optional()->randomFloat(2, 0, 100),
            'followers_gained' => fake()->optional()->numberBetween(0, 200),
            'metadata' => [],
            'measured_at' => now(),
        ];
    }
}
```

`database/factories/LlmUsageLogFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ContentProject;
use App\Models\Enums\LlmUsageLogStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class LlmUsageLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_project_id' => ContentProject::factory(),
            'purpose' => fake()->randomElement(['script', 'idea', 'quality_check', 'captions']),
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'prompt_tokens' => fake()->numberBetween(50, 2000),
            'completion_tokens' => fake()->numberBetween(50, 2000),
            'cost' => fake()->randomFloat(6, 0.0001, 0.5),
            'duration_ms' => fake()->numberBetween(200, 5000),
            'status' => LlmUsageLogStatus::Success,
            'error_message' => null,
            'metadata' => [],
        ];
    }
}
```

- [ ] **Step 5: Write the model test**

Create `tests/Feature/PublishingAnalyticsModelsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\LlmUsageLog;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\VideoMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishingAnalyticsModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_social_account_tokens_are_encrypted_at_rest(): void
    {
        $account = SocialAccount::factory()->create(['access_token' => 'plaintext-secret-token']);

        $raw = \DB::table('social_accounts')->where('id', $account->id)->value('access_token');

        $this->assertNotSame('plaintext-secret-token', $raw);
        $this->assertSame('plaintext-secret-token', $account->fresh()->access_token);
    }

    public function test_social_account_tokens_are_hidden_from_serialization(): void
    {
        $account = SocialAccount::factory()->create();

        $this->assertArrayNotHasKey('access_token', $account->toArray());
        $this->assertArrayNotHasKey('refresh_token', $account->toArray());
    }

    public function test_publication_belongs_to_video_and_social_account(): void
    {
        $publication = Publication::factory()->create();

        $this->assertNotNull($publication->video);
        $this->assertNotNull($publication->socialAccount);
    }

    public function test_video_metric_belongs_to_publication_and_deletes_with_it(): void
    {
        $publication = Publication::factory()->create();
        $metric = VideoMetric::factory()->create(['publication_id' => $publication->id]);

        $publication->delete();

        $this->assertDatabaseMissing('video_metrics', ['id' => $metric->id]);
    }

    public function test_llm_usage_log_survives_content_project_deletion(): void
    {
        $log = LlmUsageLog::factory()->create();
        $projectId = $log->content_project_id;

        \App\Models\ContentProject::find($projectId)->delete();

        $this->assertDatabaseHas('llm_usage_logs', ['id' => $log->id, 'content_project_id' => null]);
    }
}
```

- [ ] **Step 6: Run migrations and tests**

```bash
php artisan migrate:fresh --database=pgsql
php artisan test --filter=PublishingAnalyticsModelsTest
vendor/bin/pint --test
```

Expected: all 5 tests pass, Pint clean.

- [ ] **Step 7: Commit**

```bash
git add database/migrations database/factories app/Models tests/Feature/PublishingAnalyticsModelsTest.php
git commit -m "Add SocialAccount, Publication, VideoMetric, LlmUsageLog models with migrations and factories"
```

---

### Task 5: DatabaseSeeder + admin user

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php`

**Interfaces:**
- Consumes: every factory from Tasks 2-4, `App\Models\User` (stock).
- Produces: `php artisan migrate:fresh --seed` populates a realistic demo dataset and one
  admin `User` — later phases (and this phase's Task 10 Filament check) log in as this
  user.

- [ ] **Step 1: Rewrite `database/seeders/DatabaseSeeder.php`**

```php
<?php

namespace Database\Seeders;

use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\LlmUsageLog;
use App\Models\MediaAsset;
use App\Models\Publication;
use App\Models\Script;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoMetric;
use App\Models\VideoScene;
use App\Models\Voiceover;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@autocontent.test',
            'password' => bcrypt('password'),
        ]);

        MediaAsset::factory()->count(10)->create();

        ContentProject::factory()
            ->count(3)
            ->has(
                ContentIdea::factory()
                    ->count(3)
                    ->has(Script::factory()->count(1))
            )
            ->has(SocialAccount::factory()->count(2))
            ->create()
            ->each(function (ContentProject $project) {
                // Re-load explicitly: factory `has()` does not reliably leave these
                // relations set on the in-memory model after ->create().
                $project->load(['contentIdeas.scripts', 'socialAccounts']);

                $idea = $project->contentIdeas->first();
                $script = $idea?->scripts->first();

                $video = Video::factory()->create([
                    'content_project_id' => $project->id,
                    'content_idea_id' => $idea->id,
                    'script_id' => $script->id,
                ]);

                VideoScene::factory()->count(4)->create(['video_id' => $video->id]);
                Voiceover::factory()->create(['video_id' => $video->id]);

                $account = $project->socialAccounts->first();
                if ($account) {
                    $publication = Publication::factory()->create([
                        'video_id' => $video->id,
                        'social_account_id' => $account->id,
                    ]);

                    VideoMetric::factory()->create(['publication_id' => $publication->id]);
                }

                LlmUsageLog::factory()->count(2)->create(['content_project_id' => $project->id]);
            });
    }
}
```

Note: `ContentIdea::factory()->count(3)->has(Script::factory()->count(1))` produces 3
ideas per project, each with its own script — `has()` scopes each idea's script to that
idea correctly. The explicit `$project->load(...)` before reading `contentIdeas`/
`socialAccounts` in the callback above is required because factory `has()` populates the
database but does not reliably leave the relation set on the in-memory `$project`
instance returned by `->create()`.

- [ ] **Step 2: Run and verify**

```bash
php artisan migrate:fresh --seed --database=pgsql
php artisan tinker --execute="echo \App\Models\ContentProject::count() . ' projects, ' . \App\Models\Video::count() . ' videos, ' . \App\Models\User::where('email', 'admin@autocontent.test')->exists() . ' admin exists';"
```

Expected: `3 projects, 3 videos, 1 admin exists`.

- [ ] **Step 3: Run full suite (regression check) and Pint**

```bash
php artisan test
vendor/bin/pint --test
```

Expected: all tests from Tasks 1-4 still pass.

- [ ] **Step 4: Commit**

```bash
git add database/seeders/DatabaseSeeder.php
git commit -m "Seed demo content pipeline data and an admin user"
```

---

### Task 6: LLM DTOs, `LlmProviderInterface`, `FakeLlmProvider`, `config/llm.php`

**Files:**
- Create: `app/Domain/Llm/LlmRequest.php`
- Create: `app/Domain/Llm/LlmResponse.php`
- Create: `app/Domain/Llm/LlmProviderInterface.php`
- Create: `app/Domain/Llm/Providers/FakeLlmProvider.php`
- Create: `config/llm.php`
- Test: `tests/Unit/Domain/Llm/FakeLlmProviderTest.php`

**Interfaces:**
- Consumes: nothing beyond stock Laravel.
- Produces: `App\Domain\Llm\LlmRequest` (readonly: `purpose, messages, responseSchema?,
  model?, temperature, maxTokens?`), `App\Domain\Llm\LlmResponse` (readonly: `content,
  provider, model, promptTokens, completionTokens, metadata`),
  `App\Domain\Llm\LlmProviderInterface::complete(LlmRequest): LlmResponse`,
  `App\Domain\Llm\Providers\FakeLlmProvider` with a `respondWith(string $content,
  int $promptTokens = 10, int $completionTokens = 10): static` fluent setter — Task 7's
  `LlmManager` tests and Task 8-9's providers all implement/consume this exact
  interface and these exact DTO shapes.

- [ ] **Step 1: DTOs**

`app/Domain/Llm/LlmRequest.php`:

```php
<?php

namespace App\Domain\Llm;

final class LlmRequest
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function __construct(
        public readonly string $purpose,
        public readonly array $messages,
        public readonly ?string $responseSchema = null,
        public readonly ?string $model = null,
        public readonly float $temperature = 0.7,
        public readonly ?int $maxTokens = null,
    ) {}
}
```

`app/Domain/Llm/LlmResponse.php`:

```php
<?php

namespace App\Domain\Llm;

final class LlmResponse
{
    /**
     * @param  array<string, mixed>  $metadata
     */
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

- [ ] **Step 2: Interface**

`app/Domain/Llm/LlmProviderInterface.php`:

```php
<?php

namespace App\Domain\Llm;

interface LlmProviderInterface
{
    public function complete(LlmRequest $request): LlmResponse;
}
```

- [ ] **Step 3: `FakeLlmProvider`**

`app/Domain/Llm/Providers/FakeLlmProvider.php`:

```php
<?php

namespace App\Domain\Llm\Providers;

use App\Domain\Llm\LlmProviderInterface;
use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\LlmResponse;

class FakeLlmProvider implements LlmProviderInterface
{
    private string $content = '{"ok":true}';

    private int $promptTokens = 10;

    private int $completionTokens = 10;

    public function respondWith(string $content, int $promptTokens = 10, int $completionTokens = 10): static
    {
        $this->content = $content;
        $this->promptTokens = $promptTokens;
        $this->completionTokens = $completionTokens;

        return $this;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        return new LlmResponse(
            content: $this->content,
            provider: 'fake',
            model: $request->model ?? 'fake-model',
            promptTokens: $this->promptTokens,
            completionTokens: $this->completionTokens,
        );
    }
}
```

- [ ] **Step 4: `config/llm.php`**

```php
<?php

return [
    'default_provider' => env('LLM_DEFAULT_PROVIDER', 'openai'),
    'default_model' => env('LLM_DEFAULT_MODEL', 'gpt-4o-mini'),

    'providers' => [
        'openai' => [
            'driver' => \App\Domain\Llm\Providers\OpenAiLlmProvider::class,
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'models' => [
                'gpt-4o' => ['input_cost_per_1k' => 0.0025, 'output_cost_per_1k' => 0.01],
                'gpt-4o-mini' => ['input_cost_per_1k' => 0.00015, 'output_cost_per_1k' => 0.0006],
            ],
        ],

        'anthropic' => [
            'driver' => \App\Domain\Llm\Providers\AnthropicLlmProvider::class,
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'models' => [
                'claude-opus-4' => ['input_cost_per_1k' => 0.015, 'output_cost_per_1k' => 0.075],
                'claude-haiku-4.5' => ['input_cost_per_1k' => 0.001, 'output_cost_per_1k' => 0.005],
            ],
        ],

        'fake' => [
            'driver' => \App\Domain\Llm\Providers\FakeLlmProvider::class,
            'models' => [
                'fake-model' => ['input_cost_per_1k' => 0, 'output_cost_per_1k' => 0],
            ],
        ],
    ],
];
```

(References to `OpenAiLlmProvider`/`AnthropicLlmProvider` classes that don't exist yet
are fine — PHP only resolves the class string when `App::make()` instantiates it, which
happens in Task 7+. This task only creates `FakeLlmProvider`.)

- [ ] **Step 5: Write the test**

Create `tests/Unit/Domain/Llm/FakeLlmProviderTest.php`:

```php
<?php

namespace Tests\Unit\Domain\Llm;

use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\Providers\FakeLlmProvider;
use PHPUnit\Framework\TestCase;

class FakeLlmProviderTest extends TestCase
{
    public function test_it_returns_the_configured_response(): void
    {
        $provider = new FakeLlmProvider();
        $provider->respondWith('{"title":"Hello"}', promptTokens: 42, completionTokens: 7);

        $response = $provider->complete(new LlmRequest(purpose: 'script', messages: [
            ['role' => 'user', 'content' => 'hi'],
        ]));

        $this->assertSame('{"title":"Hello"}', $response->content);
        $this->assertSame('fake', $response->provider);
        $this->assertSame(42, $response->promptTokens);
        $this->assertSame(7, $response->completionTokens);
    }

    public function test_it_defaults_to_a_generic_ok_response(): void
    {
        $response = (new FakeLlmProvider())->complete(new LlmRequest(purpose: 'idea', messages: []));

        $this->assertSame('{"ok":true}', $response->content);
    }
}
```

- [ ] **Step 6: Run tests**

```bash
php artisan test --filter=FakeLlmProviderTest
vendor/bin/pint --test
```

Expected: 2 tests pass, Pint clean.

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Llm config/llm.php tests/Unit/Domain/Llm/FakeLlmProviderTest.php
git commit -m "Add LLM DTOs, LlmProviderInterface, FakeLlmProvider, config/llm.php"
```

---

### Task 7: `LlmManager` — resolution priority + `complete()` + usage logging

**Files:**
- Create: `app/Domain/Llm/LlmManagerInterface.php`
- Create: `app/Domain/Llm/ResolvedLlmTarget.php`
- Create: `app/Domain/Llm/LlmManager.php`
- Create: `app/Providers/LlmServiceProvider.php`
- Modify: `bootstrap/providers.php` (register `LlmServiceProvider`)
- Test: `tests/Unit/Domain/Llm/LlmManagerTest.php`
- Test: `tests/Feature/Domain/Llm/LlmManagerLoggingTest.php`

**Interfaces:**
- Consumes: `App\Domain\Llm\LlmProviderInterface`/`LlmRequest`/`LlmResponse` and
  `App\Domain\Llm\Providers\FakeLlmProvider` (Task 6), `App\Models\ContentProject`
  (Task 2), `App\Models\LlmUsageLog` (Task 4), `config('llm.*')` (Task 6).
- Produces: `App\Domain\Llm\LlmManagerInterface::resolve(?ContentProject $project,
  string $purpose, ?string $providerOverride = null, ?string $modelOverride = null):
  ResolvedLlmTarget` and `::complete(?ContentProject $project, string $purpose,
  array $messages, ?string $responseSchema = null, ?string $providerOverride = null,
  ?string $modelOverride = null, float $temperature = 0.7, ?int $maxTokens = null):
  LlmResponse` — this is the exact entry point Phase 2's `GenerateScriptService` etc.
  will call. `App\Domain\Llm\ResolvedLlmTarget` (readonly: `provider` (an
  `LlmProviderInterface` instance), `providerName` (string), `model` (string)).

- [ ] **Step 1: `ResolvedLlmTarget`**

`app/Domain/Llm/ResolvedLlmTarget.php`:

```php
<?php

namespace App\Domain\Llm;

final class ResolvedLlmTarget
{
    public function __construct(
        public readonly LlmProviderInterface $provider,
        public readonly string $providerName,
        public readonly string $model,
    ) {}
}
```

- [ ] **Step 2: `LlmManagerInterface`**

`app/Domain/Llm/LlmManagerInterface.php`:

```php
<?php

namespace App\Domain\Llm;

use App\Models\ContentProject;

interface LlmManagerInterface
{
    public function resolve(
        ?ContentProject $project,
        string $purpose,
        ?string $providerOverride = null,
        ?string $modelOverride = null,
    ): ResolvedLlmTarget;

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function complete(
        ?ContentProject $project,
        string $purpose,
        array $messages,
        ?string $responseSchema = null,
        ?string $providerOverride = null,
        ?string $modelOverride = null,
        float $temperature = 0.7,
        ?int $maxTokens = null,
    ): LlmResponse;
}
```

- [ ] **Step 3: `LlmManager`**

`app/Domain/Llm/LlmManager.php`:

```php
<?php

namespace App\Domain\Llm;

use App\Models\ContentProject;
use App\Models\Enums\LlmUsageLogStatus;
use App\Models\LlmUsageLog;
use Illuminate\Contracts\Container\Container;
use Throwable;

class LlmManager implements LlmManagerInterface
{
    /** @var array<string, LlmProviderInterface> */
    private array $providers = [];

    public function __construct(private readonly Container $container) {}

    public function resolve(
        ?ContentProject $project,
        string $purpose,
        ?string $providerOverride = null,
        ?string $modelOverride = null,
    ): ResolvedLlmTarget {
        $providerName = $providerOverride
            ?? $this->settingFor($project, $purpose, 'provider')
            ?? $this->settingFor($project, 'default', 'provider')
            ?? config('llm.default_provider');

        $model = $modelOverride
            ?? $this->settingFor($project, $purpose, 'model')
            ?? $this->settingFor($project, 'default', 'model')
            ?? config('llm.default_model');

        return new ResolvedLlmTarget(
            provider: $this->providerInstance($providerName),
            providerName: $providerName,
            model: $model,
        );
    }

    public function complete(
        ?ContentProject $project,
        string $purpose,
        array $messages,
        ?string $responseSchema = null,
        ?string $providerOverride = null,
        ?string $modelOverride = null,
        float $temperature = 0.7,
        ?int $maxTokens = null,
    ): LlmResponse {
        $target = $this->resolve($project, $purpose, $providerOverride, $modelOverride);

        $request = new LlmRequest(
            purpose: $purpose,
            messages: $messages,
            responseSchema: $responseSchema,
            model: $target->model,
            temperature: $temperature,
            maxTokens: $maxTokens,
        );

        $startedAt = microtime(true);

        try {
            $response = $target->provider->complete($request);

            $this->log($project, $purpose, $target, $response, $startedAt, LlmUsageLogStatus::Success);

            return $response;
        } catch (Throwable $exception) {
            $this->logFailure($project, $purpose, $target, $startedAt, $exception);

            throw $exception;
        }
    }

    private function settingFor(?ContentProject $project, string $key, string $field): ?string
    {
        return $project?->settings['ai'][$key][$field] ?? null;
    }

    private function providerInstance(string $name): LlmProviderInterface
    {
        if (! isset($this->providers[$name])) {
            $driver = config("llm.providers.{$name}.driver");

            if ($driver === null) {
                throw new \InvalidArgumentException("Unknown LLM provider [{$name}].");
            }

            $this->providers[$name] = $this->container->make($driver);
        }

        return $this->providers[$name];
    }

    private function log(
        ?ContentProject $project,
        string $purpose,
        ResolvedLlmTarget $target,
        LlmResponse $response,
        float $startedAt,
        LlmUsageLogStatus $status,
    ): void {
        LlmUsageLog::create([
            'content_project_id' => $project?->id,
            'purpose' => $purpose,
            'provider' => $target->providerName,
            'model' => $response->model,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'cost' => $this->estimateCost($target->providerName, $response),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'status' => $status,
            'metadata' => [],
        ]);
    }

    private function logFailure(
        ?ContentProject $project,
        string $purpose,
        ResolvedLlmTarget $target,
        float $startedAt,
        Throwable $exception,
    ): void {
        LlmUsageLog::create([
            'content_project_id' => $project?->id,
            'purpose' => $purpose,
            'provider' => $target->providerName,
            'model' => $target->model,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'cost' => null,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'status' => LlmUsageLogStatus::Failed,
            'error_message' => $exception->getMessage(),
            'metadata' => [],
        ]);
    }

    private function estimateCost(string $providerName, LlmResponse $response): ?float
    {
        $pricing = config("llm.providers.{$providerName}.models.{$response->model}");

        if ($pricing === null) {
            return null;
        }

        return round(
            ($response->promptTokens / 1000) * $pricing['input_cost_per_1k']
            + ($response->completionTokens / 1000) * $pricing['output_cost_per_1k'],
            6
        );
    }
}
```

- [ ] **Step 4: Bind the interface in a service provider**

`app/Providers/LlmServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Domain\Llm\LlmManager;
use App\Domain\Llm\LlmManagerInterface;
use Illuminate\Support\ServiceProvider;

class LlmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LlmManagerInterface::class, LlmManager::class);
    }
}
```

Register it in `bootstrap/providers.php` — add `App\Providers\LlmServiceProvider::class`
to the returned array, alongside the existing `App\Providers\AppServiceProvider::class`
(and whatever `horizon:install`/`filament:install` added in Task 1).

- [ ] **Step 5: Write `LlmManagerTest` (unit, no DB)**

Create `tests/Unit/Domain/Llm/LlmManagerTest.php` — bind `FakeLlmProvider` into the
container under the `fake` key so `resolve()`/`complete()` exercise real container
resolution without hitting `config()` mutation per test:

```php
<?php

namespace Tests\Unit\Domain\Llm;

use App\Domain\Llm\LlmManager;
use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Models\ContentProject;
use Illuminate\Container\Container;
use Tests\TestCase;

class LlmManagerTest extends TestCase
{
    public function test_resolve_falls_back_to_global_default_with_no_project(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $manager = new LlmManager(Container::getInstance());

        $target = $manager->resolve(null, 'script');

        $this->assertSame('fake', $target->providerName);
        $this->assertSame('fake-model', $target->model);
        $this->assertInstanceOf(FakeLlmProvider::class, $target->provider);
    }

    public function test_resolve_prefers_project_purpose_setting_over_default(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $project = new ContentProject(['settings' => [
            'ai' => [
                'default' => ['provider' => 'fake', 'model' => 'fake-model'],
                'script' => ['provider' => 'fake', 'model' => 'purpose-specific-model'],
            ],
        ]]);

        $manager = new LlmManager(Container::getInstance());

        $target = $manager->resolve($project, 'script');

        $this->assertSame('purpose-specific-model', $target->model);
    }

    public function test_resolve_prefers_project_default_over_global_default(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'global-default-model');

        $project = new ContentProject(['settings' => [
            'ai' => ['default' => ['provider' => 'fake', 'model' => 'project-default-model']],
        ]]);

        $manager = new LlmManager(Container::getInstance());

        $target = $manager->resolve($project, 'idea');

        $this->assertSame('project-default-model', $target->model);
    }

    public function test_explicit_override_wins_over_everything(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'global-default-model');

        $project = new ContentProject(['settings' => [
            'ai' => [
                'default' => ['provider' => 'fake', 'model' => 'project-default-model'],
                'idea' => ['provider' => 'fake', 'model' => 'purpose-model'],
            ],
        ]]);

        $manager = new LlmManager(Container::getInstance());

        $target = $manager->resolve($project, 'idea', modelOverride: 'explicit-model');

        $this->assertSame('explicit-model', $target->model);
    }
}
```

- [ ] **Step 6: Write `LlmManagerLoggingTest` (feature, needs DB for `LlmUsageLog`)**

Create `tests/Feature/Domain/Llm/LlmManagerLoggingTest.php`:

```php
<?php

namespace Tests\Feature\Domain\Llm;

use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Models\ContentProject;
use App\Models\Enums\LlmUsageLogStatus;
use App\Models\LlmUsageLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LlmManagerLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_logs_a_successful_call(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider())->respondWith('{"ok":true}', 100, 50);
        });

        $project = ContentProject::factory()->create();

        $manager = $this->app->make(LlmManagerInterface::class);
        $response = $manager->complete($project, 'script', [['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('{"ok":true}', $response->content);

        $this->assertDatabaseHas('llm_usage_logs', [
            'content_project_id' => $project->id,
            'purpose' => 'script',
            'provider' => 'fake',
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'status' => LlmUsageLogStatus::Success->value,
        ]);
    }

    public function test_complete_logs_a_failed_call_and_rethrows(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return new class extends FakeLlmProvider
            {
                public function complete(\App\Domain\Llm\LlmRequest $request): \App\Domain\Llm\LlmResponse
                {
                    throw new \RuntimeException('provider unavailable');
                }
            };
        });

        $manager = $this->app->make(LlmManagerInterface::class);

        $this->expectException(\RuntimeException::class);

        try {
            $manager->complete(null, 'idea', [['role' => 'user', 'content' => 'hi']]);
        } finally {
            $this->assertDatabaseHas('llm_usage_logs', [
                'purpose' => 'idea',
                'provider' => 'fake',
                'status' => LlmUsageLogStatus::Failed->value,
                'error_message' => 'provider unavailable',
            ]);
        }
    }
}
```

- [ ] **Step 7: Run tests**

```bash
php artisan test --filter=LlmManagerTest
php artisan test --filter=LlmManagerLoggingTest
vendor/bin/pint --test
```

Expected: 4 + 2 tests pass, Pint clean. This satisfies the Phase 1 DoD item
("отримати правильний resolved provider/model через юніт-тест на `LlmManager`").

- [ ] **Step 8: Commit**

```bash
git add app/Domain/Llm app/Providers/LlmServiceProvider.php bootstrap/providers.php tests/Unit/Domain/Llm/LlmManagerTest.php tests/Feature/Domain/Llm/LlmManagerLoggingTest.php
git commit -m "Add LlmManager with priority resolution and LlmUsageLog logging"
```

---

### Task 8: `OpenAiLlmProvider`

**Files:**
- Create: `app/Domain/Llm/Providers/OpenAiLlmProvider.php`
- Test: `tests/Unit/Domain/Llm/OpenAiLlmProviderTest.php`

**Interfaces:**
- Consumes: `App\Domain\Llm\LlmProviderInterface`/`LlmRequest`/`LlmResponse` (Task 6),
  `config('llm.providers.openai.*')` (Task 6).
- Produces: `App\Domain\Llm\Providers\OpenAiLlmProvider` — resolvable by `LlmManager`
  (Task 7) as the `openai` driver, per `config/llm.php`.

- [ ] **Step 1: Implement the provider**

`app/Domain/Llm/Providers/OpenAiLlmProvider.php`:

```php
<?php

namespace App\Domain\Llm\Providers;

use App\Domain\Llm\LlmProviderInterface;
use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\LlmResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiLlmProvider implements LlmProviderInterface
{
    public function complete(LlmRequest $request): LlmResponse
    {
        $payload = [
            'model' => $request->model ?? config('llm.default_model'),
            'messages' => $request->messages,
            'temperature' => $request->temperature,
        ];

        if ($request->maxTokens !== null) {
            $payload['max_tokens'] = $request->maxTokens;
        }

        if ($request->responseSchema !== null) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = Http::withToken(config('llm.providers.openai.api_key'))
            ->baseUrl(config('llm.providers.openai.base_url'))
            ->post('/chat/completions', $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                'OpenAI request failed: '.$response->status().' '.$response->body()
            );
        }

        $data = $response->json();

        return new LlmResponse(
            content: $data['choices'][0]['message']['content'] ?? '',
            provider: 'openai',
            model: $data['model'] ?? $payload['model'],
            promptTokens: $data['usage']['prompt_tokens'] ?? 0,
            completionTokens: $data['usage']['completion_tokens'] ?? 0,
        );
    }
}
```

- [ ] **Step 2: Write the test (mocked HTTP — no real API calls)**

Create `tests/Unit/Domain/Llm/OpenAiLlmProviderTest.php`:

```php
<?php

namespace Tests\Unit\Domain\Llm;

use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\Providers\OpenAiLlmProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiLlmProviderTest extends TestCase
{
    public function test_it_parses_a_successful_response(): void
    {
        config()->set('llm.providers.openai.api_key', 'test-key');
        config()->set('llm.providers.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [
                    ['message' => ['content' => '{"title":"Hello"}']],
                ],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30],
            ], 200),
        ]);

        $provider = new OpenAiLlmProvider();
        $response = $provider->complete(new LlmRequest(
            purpose: 'script',
            messages: [['role' => 'user', 'content' => 'hi']],
            model: 'gpt-4o-mini',
        ));

        $this->assertSame('{"title":"Hello"}', $response->content);
        $this->assertSame('openai', $response->provider);
        $this->assertSame(120, $response->promptTokens);
        $this->assertSame(30, $response->completionTokens);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/chat/completions'
                && $request['model'] === 'gpt-4o-mini'
                && $request->hasHeader('Authorization', 'Bearer test-key');
        });
    }

    public function test_it_throws_on_a_failed_response(): void
    {
        config()->set('llm.providers.openai.api_key', 'test-key');
        config()->set('llm.providers.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'api.openai.com/*' => Http::response(['error' => 'rate limited'], 429),
        ]);

        $provider = new OpenAiLlmProvider();

        $this->expectException(\RuntimeException::class);

        $provider->complete(new LlmRequest(purpose: 'script', messages: [], model: 'gpt-4o-mini'));
    }
}
```

- [ ] **Step 3: Run tests**

```bash
php artisan test --filter=OpenAiLlmProviderTest
vendor/bin/pint --test
```

Expected: 2 tests pass, Pint clean. Confirm no real network call was attempted (the
`Http::fake()` in the test would throw `ConnectionException` if the mock's URL pattern
didn't match a real outgoing request — a pass here proves the request shape matched).

- [ ] **Step 4: Commit**

```bash
git add app/Domain/Llm/Providers/OpenAiLlmProvider.php tests/Unit/Domain/Llm/OpenAiLlmProviderTest.php
git commit -m "Add OpenAiLlmProvider"
```

---

### Task 9: `AnthropicLlmProvider`

**Files:**
- Create: `app/Domain/Llm/Providers/AnthropicLlmProvider.php`
- Test: `tests/Unit/Domain/Llm/AnthropicLlmProviderTest.php`

**Interfaces:**
- Consumes: `App\Domain\Llm\LlmProviderInterface`/`LlmRequest`/`LlmResponse` (Task 6),
  `config('llm.providers.anthropic.*')` (Task 6).
- Produces: `App\Domain\Llm\Providers\AnthropicLlmProvider` — resolvable by `LlmManager`
  (Task 7) as the `anthropic` driver, per `config/llm.php`.

- [ ] **Step 1: Implement the provider**

Anthropic's Messages API takes `system` separately from the `messages` array (unlike
OpenAI, which allows a `role: system` message inline) and requires an
`anthropic-version` header. Split any `role === 'system'` entries out of
`$request->messages` into the top-level `system` field.

`app/Domain/Llm/Providers/AnthropicLlmProvider.php`:

```php
<?php

namespace App\Domain\Llm\Providers;

use App\Domain\Llm\LlmProviderInterface;
use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\LlmResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicLlmProvider implements LlmProviderInterface
{
    public function complete(LlmRequest $request): LlmResponse
    {
        $systemMessages = array_values(array_filter(
            $request->messages,
            fn (array $message) => $message['role'] === 'system'
        ));

        $userMessages = array_values(array_filter(
            $request->messages,
            fn (array $message) => $message['role'] !== 'system'
        ));

        $payload = [
            'model' => $request->model ?? config('llm.default_model'),
            'messages' => $userMessages,
            'max_tokens' => $request->maxTokens ?? 1024,
            'temperature' => $request->temperature,
        ];

        if ($systemMessages !== []) {
            $payload['system'] = implode("\n\n", array_column($systemMessages, 'content'));
        }

        $response = Http::withHeaders([
            'x-api-key' => config('llm.providers.anthropic.api_key'),
            'anthropic-version' => '2023-06-01',
        ])
            ->baseUrl(config('llm.providers.anthropic.base_url'))
            ->post('/messages', $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                'Anthropic request failed: '.$response->status().' '.$response->body()
            );
        }

        $data = $response->json();

        return new LlmResponse(
            content: $data['content'][0]['text'] ?? '',
            provider: 'anthropic',
            model: $data['model'] ?? $payload['model'],
            promptTokens: $data['usage']['input_tokens'] ?? 0,
            completionTokens: $data['usage']['output_tokens'] ?? 0,
        );
    }
}
```

- [ ] **Step 2: Write the test**

Create `tests/Unit/Domain/Llm/AnthropicLlmProviderTest.php`:

```php
<?php

namespace Tests\Unit\Domain\Llm;

use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\Providers\AnthropicLlmProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicLlmProviderTest extends TestCase
{
    public function test_it_parses_a_successful_response(): void
    {
        config()->set('llm.providers.anthropic.api_key', 'test-key');
        config()->set('llm.providers.anthropic.base_url', 'https://api.anthropic.com/v1');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-haiku-4.5',
                'content' => [['type' => 'text', 'text' => '{"title":"Hello"}']],
                'usage' => ['input_tokens' => 80, 'output_tokens' => 20],
            ], 200),
        ]);

        $provider = new AnthropicLlmProvider();
        $response = $provider->complete(new LlmRequest(
            purpose: 'quality_check',
            messages: [
                ['role' => 'system', 'content' => 'You are a QA reviewer.'],
                ['role' => 'user', 'content' => 'Review this script.'],
            ],
            model: 'claude-haiku-4.5',
        ));

        $this->assertSame('{"title":"Hello"}', $response->content);
        $this->assertSame('anthropic', $response->provider);
        $this->assertSame(80, $response->promptTokens);
        $this->assertSame(20, $response->completionTokens);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'test-key')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && $request['system'] === 'You are a QA reviewer.'
                && count($request['messages']) === 1;
        });
    }

    public function test_it_throws_on_a_failed_response(): void
    {
        config()->set('llm.providers.anthropic.api_key', 'test-key');
        config()->set('llm.providers.anthropic.base_url', 'https://api.anthropic.com/v1');

        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529),
        ]);

        $provider = new AnthropicLlmProvider();

        $this->expectException(\RuntimeException::class);

        $provider->complete(new LlmRequest(purpose: 'script', messages: [], model: 'claude-haiku-4.5'));
    }
}
```

- [ ] **Step 3: Run tests**

```bash
php artisan test --filter=AnthropicLlmProviderTest
vendor/bin/pint --test
```

Expected: 2 tests pass, Pint clean.

- [ ] **Step 4: Commit**

```bash
git add app/Domain/Llm/Providers/AnthropicLlmProvider.php tests/Unit/Domain/Llm/AnthropicLlmProviderTest.php
git commit -m "Add AnthropicLlmProvider"
```

---

### Task 10: Filament resources for all 11 models

**Files:**
- Create: `app/Filament/Resources/ContentProjectResource.php` (+ generated Pages)
- Create: `app/Filament/Resources/ContentIdeaResource.php` (+ generated Pages)
- Create: `app/Filament/Resources/ScriptResource.php` (+ generated Pages)
- Create: `app/Filament/Resources/VideoResource.php` (+ generated Pages)
- Create: `app/Filament/Resources/VideoSceneResource.php` (+ generated Pages)
- Create: `app/Filament/Resources/MediaAssetResource.php` (+ generated Pages)
- Create: `app/Filament/Resources/VoiceoverResource.php` (+ generated Pages)
- Create: `app/Filament/Resources/SocialAccountResource.php` (+ generated Pages)
- Create: `app/Filament/Resources/PublicationResource.php` (+ generated Pages)
- Create: `app/Filament/Resources/VideoMetricResource.php` (+ generated Pages)
- Create: `app/Filament/Resources/LlmUsageLogResource.php` (+ generated Pages)
- Test: `tests/Feature/FilamentResourcesTest.php`

**Interfaces:**
- Consumes: every model from Tasks 2-4, the admin `User` from Task 5.
- Produces: Filament navigation entries reachable at `/admin/{resource-slug}` — this is
  the Phase 1 DoD's "у Filament видно всі розділи моделей".

- [ ] **Step 1: Generate each resource**

```bash
php artisan make:filament-resource ContentProject --generate
php artisan make:filament-resource ContentIdea --generate
php artisan make:filament-resource Script --generate
php artisan make:filament-resource Video --generate
php artisan make:filament-resource VideoScene --generate
php artisan make:filament-resource MediaAsset --generate
php artisan make:filament-resource Voiceover --generate
php artisan make:filament-resource SocialAccount --generate
php artisan make:filament-resource Publication --generate
php artisan make:filament-resource VideoMetric --generate
php artisan make:filament-resource LlmUsageLog --generate
```

(`LlmUsageLog` isn't one of TechnicalTask.md §14's named admin sections, but the Phase 1
DoD in `ROADMAP.md` says "у Filament видно **всі розділи моделей**" with no carve-out —
so all 11 models get a resource, not just the 10 TechnicalTask.md §14 names explicitly.)

`--generate` inspects each table and scaffolds form fields and table columns
automatically. Accept the generated output — no manual field-by-field customization is
required for the "read-only sufficient" DoD bar. If `--generate` produces a form field
for an encrypted column (`SocialAccountResource`'s `access_token`/`refresh_token`),
remove those two fields from the generated form and table (`app/Filament/Resources/
SocialAccountResource.php` and its `Pages/ListSocialAccounts.php` / table method) — the
model already hides them from serialization (Task 4), and Filament should not render
raw tokens in a form either, encrypted or not (TechnicalTask.md §4 "ВАЖЛИВО").

- [ ] **Step 2: Group navigation to match TechnicalTask.md §14**

Add a `protected static ?string $navigationGroup = '...';` property to each resource
class:

- `ContentProjectResource`, `ContentIdeaResource`, `ScriptResource`, `VideoResource` →
  `'Content Projects'`
- `MediaAssetResource`, `VoiceoverResource` → `'Media'`
- `SocialAccountResource`, `PublicationResource` → `'Publishing'`
- `VideoMetricResource`, `LlmUsageLogResource` → `'Analytics'`

- [ ] **Step 3: Write the reachability test**

Create `tests/Feature/FilamentResourcesTest.php` — logs in as the seeded admin and
asserts every resource's index page returns 200:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentResourcesTest extends TestCase
{
    use RefreshDatabase;

    private array $resourceSlugs = [
        'content-projects', 'content-ideas', 'scripts', 'videos', 'video-scenes',
        'media-assets', 'voiceovers', 'social-accounts', 'publications', 'video-metrics',
        'llm-usage-logs',
    ];

    public function test_every_resource_index_page_is_reachable_by_an_admin(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        foreach ($this->resourceSlugs as $slug) {
            $response = $this->get("/admin/{$slug}");

            $response->assertSuccessful();
        }
    }
}
```

If a generated resource's URL slug doesn't match the guesses above (Filament
pluralizes/kebab-cases the model name by convention, but check
`php artisan route:list | grep admin` if a specific one 404s), correct the slug in this
array to match the actual route — don't change the resource to match a wrong guess.

- [ ] **Step 4: Run tests**

```bash
php artisan test --filter=FilamentResourcesTest
vendor/bin/pint --test
```

Expected: 1 test (10 assertions across the loop) passes, Pint clean.

- [ ] **Step 5: Run the full suite one last time**

```bash
php artisan migrate:fresh --seed --database=pgsql
php artisan test
vendor/bin/pint --test
php artisan route:list > /dev/null
```

Expected: every test from Tasks 1-10 passes, Pint clean, `route:list` doesn't error.

- [ ] **Step 6: Commit**

```bash
git add app/Filament tests/Feature/FilamentResourcesTest.php
git commit -m "Add Filament resources for all Phase 1 models"
```
