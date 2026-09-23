# Phase 9a — Ідея → проєкт (skill-керована автоматизація) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Дати Claude Code (через нову project-scoped skill) можливість
перетворити сирий текстовий задум користувача на повністю налаштований
`ContentProject` і перевірене тестове відео — сам досліджуючи тему й
оцінюючи якість сценарію, спираючись лише на три тонкі CLI-адаптери.

**Architecture:** Нуль нового LLM-плумбінгу і нуль змін схеми/Jobs.
`content-project:create` закриває CLI-гап створення проєкту.
`StartVideoGenerationService` виносить існуючу inline-логіку
`VideoController::generate()` для повторного використання з нового
`content-idea:generate`. `content-idea:draft` викликає вже існуючі
чисті (без побічних ефектів) `GenerateScriptService`/
`GenerateScenesService` напряму на непер систованих (`setRelation()`,
без `save()`) моделях — нуль записів у БД. Уся "розумна" частина
(дослідження, самоперевірка, рішення про ітерації) живе в
`.claude/skills/idea-to-project/SKILL.md`, виконується самим Claude
Code, не PHP-кодом.

**Tech Stack:** Laravel 12 / PHP 8.4, Artisan console commands, PHPUnit
Feature-тести, існуючий `LlmManagerInterface`/`FakeLlmProvider`.

**Spec:** `docs/superpowers/specs/2026-09-23-phase9a-idea-to-project-design.md`

## Global Constraints

- Жодних нових міграцій/змін схеми БД.
- Жодних змін поведінки існуючих Jobs (`GenerateScriptJob`,
  `GenerateScenesJob`, ...) — лише сервісний рефакторинг контролера.
- Тести не роблять реальних HTTP/CLI-викликів назовні — лише
  `FakeLlmProvider`/фейкові реалізації `LlmManagerInterface`
  (`docs/testing.md`).
- Стиль коду — `vendor/bin/pint --test`, дефолти Laravel, без
  кастомного `pint.json`.
- Нові artisan-команди в `app/Console/Commands/` — Laravel 12
  авто-дискавері їх без реєстрації в `bootstrap/app.php`.

---

## File Structure

- `app/Domain/Content/Services/StartVideoGenerationService.php` — новий,
  орестрація "ідея → approve → dispatch script job", винесена з
  `VideoController::generate()`.
- `app/Http/Controllers/Console/VideoController.php` — модифікація:
  `generate()` делегує в `StartVideoGenerationService`.
- `app/Console/Commands/CreateContentProjectCommand.php` — новий,
  `content-project:create`.
- `app/Console/Commands/GenerateContentIdeaCommand.php` — новий,
  `content-idea:generate`.
- `app/Console/Commands/DraftContentIdeaCommand.php` — новий,
  `content-idea:draft`.
- `.claude/skills/idea-to-project/SKILL.md` — новий, skill-інструкція.
- `tests/Feature/Domain/Content/StartVideoGenerationServiceTest.php` —
  новий (той самий каталог, що вже містить `GenerateScriptServiceTest.php`
  — конвенція проєкту: domain-service тести живуть у
  `tests/Feature/Domain/...`, не `tests/Unit`).
- `tests/Feature/Console/CreateContentProjectCommandTest.php` — новий.
- `tests/Feature/Console/GenerateContentIdeaCommandTest.php` — новий.
- `tests/Feature/Console/DraftContentIdeaCommandTest.php` — новий.

---

### Task 1: `StartVideoGenerationService` + рефакторинг `VideoController::generate()`

**Files:**
- Create: `app/Domain/Content/Services/StartVideoGenerationService.php`
- Modify: `app/Http/Controllers/Console/VideoController.php`
- Test: `tests/Feature/Domain/Content/StartVideoGenerationServiceTest.php`

**Interfaces:**
- Consumes: `App\Domain\Content\Services\GenerateContentIdeaService::generate(ContentProject $project, string $topic): ContentIdea` (уже існує, персистить ідею); `App\Jobs\GenerateScriptJob::dispatch(int $contentIdeaId)` (уже існує); `App\Models\Enums\ContentIdeaStatus::Approved` (уже існує).
- Produces: `App\Domain\Content\Services\StartVideoGenerationService::generate(ContentProject $project, string $topic): ContentIdea` — використовується Task 3 (`GenerateContentIdeaCommand`) і рефакторним `VideoController`.

- [ ] **Step 1: Написати тест, що падає**

Створити `tests/Feature/Domain/Content/StartVideoGenerationServiceTest.php`:

```php
<?php

namespace Tests\Feature\Domain\Content;

use App\Domain\Content\Services\StartVideoGenerationService;
use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Jobs\GenerateScriptJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StartVideoGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_approved_idea_and_dispatches_script_generation(): void
    {
        Queue::fake();

        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['title' => 'T', 'topic' => 'ai', 'score' => 50])
            );
        });

        // 'settings' => [] avoids ContentProjectFactory's default ai.default.provider
        // outranking the 'fake' provider set via config() above.
        $project = ContentProject::factory()->create(['settings' => []]);

        $idea = app(StartVideoGenerationService::class)->generate($project, 'ai');

        $this->assertInstanceOf(ContentIdea::class, $idea);
        $this->assertSame(ContentIdeaStatus::Approved, $idea->fresh()->status);

        Queue::assertPushed(GenerateScriptJob::class, fn (GenerateScriptJob $job) => $job->contentIdeaId === $idea->id);
    }
}
```

- [ ] **Step 2: Переконатись, що тест падає**

Run: `php artisan test --filter=StartVideoGenerationServiceTest`
Expected: FAIL (клас `StartVideoGenerationService` не існує)

- [ ] **Step 3: Реалізувати сервіс**

Створити `app/Domain/Content/Services/StartVideoGenerationService.php`:

```php
<?php

namespace App\Domain\Content\Services;

use App\Jobs\GenerateScriptJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;

final class StartVideoGenerationService
{
    public function __construct(private readonly GenerateContentIdeaService $ideaService) {}

    public function generate(ContentProject $project, string $topic): ContentIdea
    {
        $idea = $this->ideaService->generate($project, $topic);

        $idea->update(['status' => ContentIdeaStatus::Approved]);

        GenerateScriptJob::dispatch($idea->id);

        return $idea;
    }
}
```

- [ ] **Step 4: Переконатись, що тест проходить**

Run: `php artisan test --filter=StartVideoGenerationServiceTest`
Expected: PASS

- [ ] **Step 5: Рефакторити `VideoController::generate()`**

У `app/Http/Controllers/Console/VideoController.php` замінити блок
імпортів (рядки 5–22):

```php
use App\Domain\Content\Services\StartVideoGenerationService;
use App\Http\Controllers\Controller;
use App\Jobs\CollectVideoAssetsJob;
use App\Jobs\GenerateScenesJob;
use App\Jobs\GenerateSubtitlesJob;
use App\Jobs\GenerateVoiceoverJob;
use App\Jobs\QualityCheckVideoJob;
use App\Jobs\RenderVideoJob;
use App\Models\ContentProject;
use App\Models\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;
```

(видалено `App\Domain\Content\Services\GenerateContentIdeaService`,
`App\Jobs\GenerateScriptJob`, `App\Models\Enums\ContentIdeaStatus` —
більше ніде в файлі не використовуються; додано
`App\Domain\Content\Services\StartVideoGenerationService`.)

І замінити тіло `generate()` (рядки 51–72):

```php
public function generate(Request $request): RedirectResponse
{
    $data = $request->validate([
        'content_project_id' => ['required', 'integer', 'exists:content_projects,id'],
        'topic' => ['required', 'string', 'max:255'],
    ]);

    try {
        app(StartVideoGenerationService::class)->generate(
            ContentProject::findOrFail($data['content_project_id']),
            $data['topic'],
        );
    } catch (Throwable $exception) {
        return back()->withErrors(['topic' => $exception->getMessage()]);
    }

    return back();
}
```

- [ ] **Step 6: Переконатись, що існуючий тест контролера й досі проходить**

Run: `php artisan test --filter=VideoControllerTest`
Expected: PASS (усі 5 тестів, поведінка не змінилась)

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Content/Services/StartVideoGenerationService.php \
  app/Http/Controllers/Console/VideoController.php \
  tests/Feature/Domain/Content/StartVideoGenerationServiceTest.php
git commit -m "refactor(console): extract StartVideoGenerationService from VideoController"
```

---

### Task 2: `content-project:create` команда

**Files:**
- Create: `app/Console/Commands/CreateContentProjectCommand.php`
- Test: `tests/Feature/Console/CreateContentProjectCommandTest.php`

**Interfaces:**
- Consumes: `App\Models\ContentProject::create(array $attributes)` (fillable: `name, slug, description, niche, language, target_platforms, status, settings`); `App\Models\Enums\SocialPlatform::cases()`.
- Produces: артефакт — рядок `ContentProject` у БД; команда друкує `Created ContentProject #{id} ({slug}).` в stdout на успіх.

- [ ] **Step 1: Написати тести, що падають**

Створити `tests/Feature/Console/CreateContentProjectCommandTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Models\ContentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CreateContentProjectCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_project_with_full_settings(): void
    {
        $exitCode = Artisan::call('content-project:create', [
            'name' => 'Stoic Wisdom',
            '--slug' => 'stoic-wisdom',
            '--niche' => 'philosophy',
            '--language' => 'en',
            '--platform' => ['tiktok', 'youtube'],
            '--tone' => 'calm',
            '--style' => 'narrative',
            '--voice' => 'voice-123',
            '--ai' => ['script:openai:gpt-4o-mini'],
        ]);

        $this->assertSame(0, $exitCode);

        $project = ContentProject::where('slug', 'stoic-wisdom')->sole();

        $this->assertSame('Stoic Wisdom', $project->name);
        $this->assertSame('philosophy', $project->niche);
        $this->assertSame('en', $project->language);
        $this->assertSame(['tiktok', 'youtube'], $project->target_platforms);
        $this->assertSame('active', $project->status);
        $this->assertSame([
            'tone' => 'calm',
            'style' => 'narrative',
            'tts' => ['voice' => 'voice-123'],
            'ai' => ['script' => ['provider' => 'openai', 'model' => 'gpt-4o-mini']],
        ], $project->settings);
    }

    public function test_it_defaults_the_slug_from_the_name(): void
    {
        Artisan::call('content-project:create', [
            'name' => 'Auto Slug Project',
            '--niche' => 'tech',
            '--language' => 'en',
            '--platform' => ['tiktok'],
        ]);

        $this->assertDatabaseHas('content_projects', ['slug' => 'auto-slug-project']);
    }

    public function test_it_fails_without_required_fields(): void
    {
        $exitCode = Artisan::call('content-project:create', [
            'name' => 'Missing Fields',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('content_projects', 0);
    }

    public function test_it_rejects_an_unknown_platform(): void
    {
        $exitCode = Artisan::call('content-project:create', [
            'name' => 'Bad Platform',
            '--niche' => 'tech',
            '--language' => 'en',
            '--platform' => ['myspace'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('content_projects', 0);
    }

    public function test_it_rejects_a_malformed_ai_option(): void
    {
        $exitCode = Artisan::call('content-project:create', [
            'name' => 'Bad Ai Option',
            '--niche' => 'tech',
            '--language' => 'en',
            '--platform' => ['tiktok'],
            '--ai' => ['script-openai-gpt'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('content_projects', 0);
    }
}
```

- [ ] **Step 2: Переконатись, що тести падають**

Run: `php artisan test --filter=CreateContentProjectCommandTest`
Expected: FAIL (команда `content-project:create` не існує)

- [ ] **Step 3: Реалізувати команду**

Створити `app/Console/Commands/CreateContentProjectCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\ContentProject;
use App\Models\Enums\SocialPlatform;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreateContentProjectCommand extends Command
{
    protected $signature = 'content-project:create
        {name : Project display name}
        {--slug= : URL slug (defaults to a slugified name)}
        {--description= : Optional description}
        {--niche= : Content niche}
        {--language= : ISO language code}
        {--platform=* : Target platform(s): tiktok|youtube|instagram|x, repeatable}
        {--tone= : settings.tone free text}
        {--style= : settings.style free text}
        {--voice= : settings.tts.voice (ElevenLabs voice id)}
        {--ai=* : purpose:provider:model, repeatable, e.g. script:openai:gpt-4o-mini}
        {--status=active : Project status}';

    protected $description = 'Create a ContentProject with full settings from the CLI (no Filament UI required).';

    public function handle(): int
    {
        $platforms = $this->option('platform');

        $validator = Validator::make([
            'name' => $this->argument('name'),
            'niche' => $this->option('niche'),
            'language' => $this->option('language'),
            'platforms' => $platforms,
        ], [
            'name' => ['required', 'string'],
            'niche' => ['required', 'string'],
            'language' => ['required', 'string'],
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['string', Rule::in(array_map(
                fn (SocialPlatform $platform): string => $platform->value,
                SocialPlatform::cases(),
            ))],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $settings = [];

        if ($this->option('tone') !== null) {
            $settings['tone'] = $this->option('tone');
        }

        if ($this->option('style') !== null) {
            $settings['style'] = $this->option('style');
        }

        if ($this->option('voice') !== null) {
            $settings['tts'] = ['voice' => $this->option('voice')];
        }

        foreach ($this->option('ai') as $spec) {
            [$purpose, $provider, $model] = array_pad(explode(':', $spec, 3), 3, null);

            if ($purpose === null || $provider === null || $model === null) {
                $this->error("Invalid --ai value [{$spec}], expected purpose:provider:model.");

                return self::FAILURE;
            }

            $settings['ai'][$purpose] = ['provider' => $provider, 'model' => $model];
        }

        $name = $this->argument('name');

        $project = ContentProject::create([
            'name' => $name,
            'slug' => $this->option('slug') ?? Str::slug($name),
            'description' => $this->option('description') ?? '',
            'niche' => $this->option('niche'),
            'language' => $this->option('language'),
            'target_platforms' => $platforms,
            'status' => $this->option('status'),
            'settings' => $settings,
        ]);

        $this->info("Created ContentProject #{$project->id} ({$project->slug}).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Переконатись, що тести проходять**

Run: `php artisan test --filter=CreateContentProjectCommandTest`
Expected: PASS (5 тестів)

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/CreateContentProjectCommand.php \
  tests/Feature/Console/CreateContentProjectCommandTest.php
git commit -m "feat(console): add content-project:create artisan command"
```

---

### Task 3: `content-idea:generate` команда

**Files:**
- Create: `app/Console/Commands/GenerateContentIdeaCommand.php`
- Test: `tests/Feature/Console/GenerateContentIdeaCommandTest.php`

**Interfaces:**
- Consumes: `App\Domain\Content\Services\StartVideoGenerationService::generate(ContentProject $project, string $topic): ContentIdea` (Task 1).
- Produces: CLI-паритет з `VideoController::generate()` — запускає весь існуючий job-ланцюжок; команда друкує `Created ContentIdea #{id} ({title}) — GenerateScriptJob dispatched.` на успіх.

- [ ] **Step 1: Написати тести, що падають**

Створити `tests/Feature/Console/GenerateContentIdeaCommandTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Jobs\GenerateScriptJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenerateContentIdeaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_approved_idea_and_dispatches_script_generation(): void
    {
        Queue::fake();

        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['title' => 'T', 'topic' => 'ai', 'score' => 50])
            );
        });

        $project = ContentProject::factory()->create(['settings' => []]);

        $exitCode = Artisan::call('content-idea:generate', [
            'project' => $project->id,
            'topic' => 'ai',
        ]);

        $this->assertSame(0, $exitCode);

        $idea = ContentIdea::where('content_project_id', $project->id)->sole();
        $this->assertSame(ContentIdeaStatus::Approved, $idea->status);

        Queue::assertPushed(GenerateScriptJob::class, fn (GenerateScriptJob $job) => $job->contentIdeaId === $idea->id);
    }

    public function test_it_fails_for_an_unknown_project(): void
    {
        $exitCode = Artisan::call('content-idea:generate', [
            'project' => 999999,
            'topic' => 'ai',
        ]);

        $this->assertSame(1, $exitCode);
    }
}
```

- [ ] **Step 2: Переконатись, що тести падають**

Run: `php artisan test --filter=GenerateContentIdeaCommandTest`
Expected: FAIL (команда `content-idea:generate` не існує)

- [ ] **Step 3: Реалізувати команду**

Створити `app/Console/Commands/GenerateContentIdeaCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Domain\Content\Services\StartVideoGenerationService;
use App\Models\ContentProject;
use Illuminate\Console\Command;
use Throwable;

class GenerateContentIdeaCommand extends Command
{
    protected $signature = 'content-idea:generate {project : ContentProject ID} {topic : Raw topic text}';

    protected $description = 'Generate an approved ContentIdea for a topic and dispatch the full video pipeline (CLI parity with Console "Generate Video").';

    public function handle(StartVideoGenerationService $service): int
    {
        $project = ContentProject::find((int) $this->argument('project'));

        if ($project === null) {
            $this->error("ContentProject [{$this->argument('project')}] not found.");

            return self::FAILURE;
        }

        try {
            $idea = $service->generate($project, $this->argument('topic'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Created ContentIdea #{$idea->id} ({$idea->title}) — GenerateScriptJob dispatched.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Переконатись, що тести проходять**

Run: `php artisan test --filter=GenerateContentIdeaCommandTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/GenerateContentIdeaCommand.php \
  tests/Feature/Console/GenerateContentIdeaCommandTest.php
git commit -m "feat(console): add content-idea:generate artisan command"
```

---

### Task 4: `content-idea:draft` команда

**Files:**
- Create: `app/Console/Commands/DraftContentIdeaCommand.php`
- Test: `tests/Feature/Console/DraftContentIdeaCommandTest.php`

**Interfaces:**
- Consumes: `App\Domain\Llm\LlmManagerInterface::resolve(?ContentProject $project, string $purpose, ...): ResolvedLlmTarget` (уже існує); `App\Domain\Content\Services\GenerateScriptService::generate(ContentIdea $idea, ResolvedLlmTarget $target): array{title, hook, script, estimated_duration, cta}` (уже існує, pure); `App\Domain\Video\Services\GenerateScenesService::generate(Script $script, ResolvedLlmTarget $target): array<int, array{type, duration, visual_query, text}>` (уже існує, pure).
- Produces: JSON у stdout `{"script": {...}, "scenes": [...]}`; нуль записів у БД.

- [ ] **Step 1: Написати тести, що падають**

Створити `tests/Feature/Console/DraftContentIdeaCommandTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Llm\LlmResponse;
use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Domain\Llm\ResolvedLlmTarget;
use App\Models\ContentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DraftContentIdeaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_previews_script_and_scenes_without_persisting_anything(): void
    {
        $project = ContentProject::factory()->create();

        $this->app->bind(LlmManagerInterface::class, fn () => new class implements LlmManagerInterface
        {
            private int $index = 0;

            /** @var array<int, string> */
            private array $responses = [
                '{"title":"T","hook":"H","script":"S","estimated_duration":42,"cta":"C"}',
                '{"scenes":[{"type":"broll","duration":5,"visual_query":"stoic bust","text":"S"}]}',
            ];

            public function resolve(?ContentProject $project, string $purpose, ?string $providerOverride = null, ?string $modelOverride = null): ResolvedLlmTarget
            {
                return new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');
            }

            public function complete(?ContentProject $project, string $purpose, array $messages, ?array $responseSchema = null, ?string $providerOverride = null, ?string $modelOverride = null, float $temperature = 0.7, ?int $maxTokens = null): LlmResponse
            {
                $content = $this->responses[$this->index] ?? end($this->responses);
                $this->index++;

                return new LlmResponse(
                    content: $content,
                    provider: 'fake',
                    model: 'fake-model',
                    promptTokens: 10,
                    completionTokens: 5,
                );
            }
        });

        $exitCode = Artisan::call('content-idea:draft', [
            'project' => $project->id,
            'title' => 'Stoic Mornings',
            'topic' => 'how stoics started their day',
        ]);

        $this->assertSame(0, $exitCode);

        $output = json_decode(Artisan::output(), true);

        $this->assertSame('T', $output['script']['title']);
        $this->assertSame('broll', $output['scenes'][0]['type']);
        $this->assertSame('stoic bust', $output['scenes'][0]['visual_query']);

        $this->assertDatabaseCount('content_ideas', 0);
        $this->assertDatabaseCount('scripts', 0);
        $this->assertDatabaseCount('videos', 0);
        $this->assertDatabaseCount('video_scenes', 0);
    }

    public function test_it_fails_for_an_unknown_project(): void
    {
        $exitCode = Artisan::call('content-idea:draft', [
            'project' => 999999,
            'title' => 'T',
            'topic' => 'x',
        ]);

        $this->assertSame(1, $exitCode);
    }
}
```

- [ ] **Step 2: Переконатись, що тести падають**

Run: `php artisan test --filter=DraftContentIdeaCommandTest`
Expected: FAIL (команда `content-idea:draft` не існує)

- [ ] **Step 3: Реалізувати команду**

Створити `app/Console/Commands/DraftContentIdeaCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Domain\Content\Exceptions\ScriptGenerationFailedException;
use App\Domain\Content\Services\GenerateScriptService;
use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Video\Exceptions\SceneGenerationFailedException;
use App\Domain\Video\Services\GenerateScenesService;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Script;
use Illuminate\Console\Command;

class DraftContentIdeaCommand extends Command
{
    protected $signature = 'content-idea:draft {project : ContentProject ID} {title : Idea title to preview} {topic : Idea topic/description to preview}';

    protected $description = 'Preview a script+scenes generation without persisting anything (no DB writes beyond reading the project).';

    public function handle(LlmManagerInterface $llmManager, GenerateScriptService $scriptService, GenerateScenesService $scenesService): int
    {
        $project = ContentProject::find((int) $this->argument('project'));

        if ($project === null) {
            $this->error("ContentProject [{$this->argument('project')}] not found.");

            return self::FAILURE;
        }

        $idea = (new ContentIdea([
            'content_project_id' => $project->id,
            'title' => $this->argument('title'),
            'topic' => $this->argument('topic'),
        ]))->setRelation('contentProject', $project);

        $target = $llmManager->resolve($project, 'script');

        try {
            $scriptData = $scriptService->generate($idea, $target);
        } catch (ScriptGenerationFailedException $exception) {
            $this->error("Script generation failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $script = (new Script([
            'content' => $scriptData['script'],
            'metadata' => ['title' => $scriptData['title']],
        ]))->setRelation('contentIdea', $idea);

        try {
            $scenes = $scenesService->generate($script, $target);
        } catch (SceneGenerationFailedException $exception) {
            $this->error("Scene generation failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->line(json_encode([
            'script' => $scriptData,
            'scenes' => $scenes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Переконатись, що тести проходять**

Run: `php artisan test --filter=DraftContentIdeaCommandTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/DraftContentIdeaCommand.php \
  tests/Feature/Console/DraftContentIdeaCommandTest.php
git commit -m "feat(console): add content-idea:draft artisan command"
```

---

### Task 5: `idea-to-project` skill

**Files:**
- Create: `.claude/skills/idea-to-project/SKILL.md`

**Interfaces:**
- Consumes: `content-project:create` (Task 2), `content-idea:draft` (Task 4), `content-idea:generate` (Task 3) — усі як зовнішні CLI-команди, викликані з тексту skill-інструкції.
- Produces: нічого програмного — цей файл читає й виконує сам Claude Code в майбутніх сесіях.

- [ ] **Step 1: Написати SKILL.md**

Створити `.claude/skills/idea-to-project/SKILL.md`:

```markdown
---
name: idea-to-project
description: Use when the user gives a raw text idea/topic for AutoContent and wants a fully configured ContentProject plus a rendered test video — this skill researches the topic itself, configures niche/tone/style, drafts and self-reviews the script/scenes before committing to a full render.
---

# Ідея → проєкт (AutoContent)

Автоматизація Phase 9a: сирий текстовий задум користувача → повністю
налаштований `ContentProject` → перевірене тестове відео. Дослідження
теми і самоперевірка якості — це твоя (Claude) власна робота, не
LLM-виклик усередині застосунку. Деталі архітектури:
`docs/superpowers/specs/2026-09-23-phase9a-idea-to-project-design.md`.

## Передумови

- `docker compose up -d --build` піднято, черга реально споживається
  (`worker`/`horizon` контейнери) — інакше `content-idea:generate`
  ніколи не завершить рендер.
- Працюєш із реальними, не Fake-провайдерами (`.env` застосунку вже
  налаштований користувачем раніше) — це реальна генерація, не тест.

## Кроки

1. **Отримай задум.** Одне речення/абзац від користувача — тема,
   можливо побажання щодо тону/платформи.

2. **Досліди тему сам.** Власні знання, без web-search. Визнач:
   - `niche` — коротка категорія (напр. `philosophy`, `finance`, `tech_news`)
   - `language` — ISO-код (`en`, `uk`, ...)
   - `tone`/`style` — вільний текст (напр. tone=`calm`, style=`narrative`)
   - `target_platforms` — підмножина `tiktok`/`youtube`/`instagram`/`x`
   - стартовий `topic` — речення для `content-idea:generate`/`content-idea:draft`

3. **Створи проєкт:**

   ```bash
   php artisan content-project:create "Назва проєкту" \
     --niche=philosophy --language=en \
     --platform=tiktok --platform=youtube \
     --tone=calm --style=narrative
   ```

   Запам'ятай надрукований `id` проєкту.

4. **Чернетка (до 2 ітерацій).** Для кожної ітерації придумай
   `title`+`topic` сам (не через LLM-виклик застосунку) і виклич:

   ```bash
   php artisan content-idea:draft {project_id} "Заголовок" "topic текст"
   ```

   Команда нічого не пише в БД — можна викликати скільки завгодно
   разів. Прочитай JSON (`script.script`, `scenes[].text`) і сам
   оціни: чи відповідає задуму користувача, чи логічна структура сцен,
   чи немає фактичних помилок/нісенітниці. Якщо ні — зміни
   `title`/`topic` (і за потреби tone/style проєкту через
   `php artisan tinker --execute="App\Models\ContentProject::find({id})->update(['settings->tone' => '...'])"`)
   і повтори. Максимум 2 повторні спроби чернетки (тобто до 3
   викликів `content-idea:draft` всього) — після цього переходь до
   фіналу з тим, що є, чесно попередивши користувача про залишкові
   сумніви.

5. **Фінал — повний рендер:**

   ```bash
   php artisan content-idea:generate {project_id} "фінальний topic текст"
   ```

   Це реальна LLM-генерація ідеї (може дати трохи інший title/topic,
   ніж чернетка — нормально) + весь автоматичний ланцюжок job'ів до
   рендеру. Зачекай завершення, періодично перевіряючи статус:

   ```bash
   php artisan tinker --execute="dump(App\Models\Video::where('content_project_id', {project_id})->latest('id')->first(['id','status','failed_stage','error_message']))"
   ```

   Статуси проходять `ScriptGenerated → VoiceGenerated → AssetsReady →
   Rendered → QualityChecked` (див. `docs/architecture.md` §2). Якщо
   `status=Failed` — прочитай `failed_stage`/`error_message`, це вже
   технічна проблема поза скоупом цього skill (не намагайся мовчки
   перезапускати рендер втретє).

6. **Звітуй користувачу:**
   - Посилання на відео (`/console/videos` або `/admin/videos/{id}`)
     і на проєкт (`/admin/content-projects/{id}`).
   - Коротко — які `niche`/`tone`/`style`/`target_platforms` обрано і
     чому, скільки чернеткових ітерацій знадобилось і що саме
     коригувалось.
   - Якщо фінал не пройшов технічну перевірку — чесно скажи це, не
     видавай за успіх.
```

- [ ] **Step 2: Перевірити файл**

Run: `cat .claude/skills/idea-to-project/SKILL.md | head -5`
Expected: бачимо YAML frontmatter з `name: idea-to-project` і
`description: ...` — Claude Code розпізнає це як валідну project-scoped
skill.

- [ ] **Step 3: Commit**

```bash
git add .claude/skills/idea-to-project/SKILL.md
git commit -m "docs(skill): add idea-to-project skill for Phase 9a"
```

---

## Manual verification (після всіх задач, перед фінальним review)

Реальний прогін через щойно написану skill (не Fake-провайдери,
реальний `docker compose` стек із запущеною чергою): взяти один
довільний текстовий задум, пройти всі 6 кроків SKILL.md, підтвердити,
що хоча б одна чернеткова ітерація справді щось скоригувала (title/
topic або tone/style), і що фінальний `content-idea:generate` довів
відео до `Rendered`/`QualityChecked` у реальній БД. Задокументувати
результат у `ROADMAP.md` (новий пункт Phase 9a, статус `[x]`) разом із
короткими нотатками фінального review — той самий формат, що вже
використано для Phase 7/8.
