# Phase 2 — Content Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** From a `ContentProject` and a topic, a real LLM call produces an approved `ContentIdea` and a completed `Script`, with the provider/model actually coming from `ContentProject.settings.ai.script` — closing DoD items 1-4 (TechnicalTask.md §24).

**Architecture:** Two new domain services in `app/Domain/Content/Services/` (`GenerateContentIdeaService`, synchronous; `GenerateScriptService`, called from a job) sit on top of the existing `LlmManagerInterface`. `GenerateScriptService` gets structured JSON out of the LLM via a retyped `LlmRequest.responseSchema` (now a JSON Schema array instead of a bare flag), which `OpenAiLlmProvider`/`AnthropicLlmProvider` translate into `response_format.json_schema` and forced tool-use respectively. `App\Jobs\GenerateScriptJob` orchestrates the idea→script transition with `ShouldBeUnique` + an in-`handle()` status guard for idempotency. Filament resources gain the actions needed to drive this from the admin panel.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL, Redis-backed queue (`laravel/horizon`), `filament/filament` v4.13, Laravel `Http` facade (no LLM SDKs).

**Spec:** `docs/superpowers/specs/2026-09-12-phase2-content-design.md`

## Global Constraints

- `LlmRequest.responseSchema` is `?array` in the shape `['name' => string, 'schema' => array, 'strict' => bool]` — a JSON Schema object, not a bare flag (spec, `responseSchema` та structured output per provider).
- `OpenAiLlmProvider` maps it to `response_format = ['type' => 'json_schema', 'json_schema' => [...]]`; `AnthropicLlmProvider` maps it to forced tool-use (`tools` + `tool_choice`) and re-serializes the `tool_use` block's `input` back into a JSON string so `LlmResponse.content` is always a raw JSON string regardless of provider (spec, same section).
- Both providers: `Http::timeout(60)->retry(3, 500, when: ...)` retrying only `ConnectionException` and 5xx `RequestException` — 4xx never retries. All failure paths (4xx, exhausted 5xx retries, exhausted connection retries) surface as `\RuntimeException` from `complete()`, preserving the existing contract (spec, Timeout/retry на HTTP-рівні).
- `LlmResponse.metadata` carries `finish_reason` (normalized: `stop`/`length`/`error`) and `provider_finish_reason` (raw). `LlmManager::log()` persists `$response->metadata` into `LlmUsageLog.metadata` instead of a hardcoded `[]` (spec, `LlmResponse.metadata`).
- `App\Models\Enums\ScriptStatus`: `Pending`/`Processing`/`Completed`/`Failed`, cast on `Script.status` — no migration needed, the column is already plain `string` (spec, `ScriptStatus` enum).
- `GenerateContentIdeaService` runs synchronously (no queue job) — ROADMAP Phase 2 and TechnicalTask.md §8 name only `GenerateScriptJob` (spec, `GenerateContentIdeaService` — без Job).
- `GenerateScriptService::generate()` does an in-process repair loop: on invalid JSON/schema mismatch, up to 2 additional LLM calls with the validation error appended to `messages`, before throwing `ScriptGenerationFailedException` (spec, `GenerateScriptService` — repair-loop).
- `GenerateScriptJob`: `$timeout = 180`, `$tries = 3`, `backoff() = [10, 30, 60]`, `ShouldBeUnique` keyed by `content_idea_id`. Idempotency inside `handle()`: no-op unless `ContentIdea.status` is `Approved`/`Processing`; `Script::firstOrCreate(['content_idea_id' => ...], [...])` reuses the same row across retries. `failed()` sets `Script.status = Failed` and reverts `ContentIdea.status` to `Approved` (spec, `GenerateScriptJob` — timeout/retry/ідемпотентність).
- "Generate Script" is only ever offered/actioned for `ContentIdea.status = Approved` (spec, `Filament`; brainstorming decision).
- No schema/model changes to `Video` or any Phase 3+ entity — Phase 2 stops at `Script` (spec, Скоуп — не входить).
- `phpunit.xml` stops hardcoding `DB_HOST`/`DB_PORT` — those come from `.env.testing` (gitignored, per-developer) going forward (spec, Тести).

---

### Task 1: Retype `responseSchema` to array + `LlmManager` persists response metadata

**Files:**
- Modify: `app/Domain/Llm/LlmRequest.php`
- Modify: `app/Domain/Llm/LlmManagerInterface.php`
- Modify: `app/Domain/Llm/LlmManager.php`
- Modify: `tests/Feature/Domain/Llm/LlmManagerLoggingTest.php`

**Interfaces:**
- Consumes: nothing new — retypes existing `LlmRequest`/`LlmManagerInterface`/`LlmManager` from Phase 1.
- Produces: `LlmRequest::$responseSchema` as `?array{name: string, schema: array<string, mixed>, strict?: bool}` (was `?string`); `LlmManagerInterface::complete()`'s `$responseSchema` param retyped the same way; `LlmManager::log()` now writes `$response->metadata` into `LlmUsageLog.metadata` — Tasks 2, 3, 5, 6 build against this signature.

- [ ] **Step 1: Retype `LlmRequest::$responseSchema`**

Edit `app/Domain/Llm/LlmRequest.php` — replace the whole class body:

```php
<?php

namespace App\Domain\Llm;

final class LlmRequest
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array{name: string, schema: array<string, mixed>, strict?: bool}|null  $responseSchema
     */
    public function __construct(
        public readonly string $purpose,
        public readonly array $messages,
        public readonly ?array $responseSchema = null,
        public readonly ?string $model = null,
        public readonly float $temperature = 0.7,
        public readonly ?int $maxTokens = null,
    ) {}
}
```

- [ ] **Step 2: Retype `LlmManagerInterface::complete()`**

Edit `app/Domain/Llm/LlmManagerInterface.php` — replace the whole class body:

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
     * @param  array{name: string, schema: array<string, mixed>, strict?: bool}|null  $responseSchema
     */
    public function complete(
        ?ContentProject $project,
        string $purpose,
        array $messages,
        ?array $responseSchema = null,
        ?string $providerOverride = null,
        ?string $modelOverride = null,
        float $temperature = 0.7,
        ?int $maxTokens = null,
    ): LlmResponse;
}
```

- [ ] **Step 3: Retype `LlmManager::complete()` and persist response metadata**

Edit `app/Domain/Llm/LlmManager.php`:

Change the `complete()` signature's `?string $responseSchema = null` to `?array $responseSchema = null`:

```php
    public function complete(
        ?ContentProject $project,
        string $purpose,
        array $messages,
        ?array $responseSchema = null,
        ?string $providerOverride = null,
        ?string $modelOverride = null,
        float $temperature = 0.7,
        ?int $maxTokens = null,
    ): LlmResponse {
```

Change `log()`'s hardcoded `'metadata' => [],` to `'metadata' => $response->metadata,`:

```php
    private function log(
        ?ContentProject $project,
        string $purpose,
        ResolvedLlmTarget $target,
        LlmResponse $response,
        float $startedAt,
    ): void {
        LlmUsageLog::create([
            'content_project_id' => $project?->id,
            'purpose' => $purpose,
            'provider' => $target->providerName,
            'model' => $response->model,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'cost' => $this->estimateCost($target->providerName, $target->model, $response),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'status' => LlmUsageLogStatus::Success,
            'metadata' => $response->metadata,
        ]);
    }
```

`logFailure()` stays untouched (still `'metadata' => []` — there is no `LlmResponse` on a thrown-exception path).

- [ ] **Step 4: Add a test proving metadata flows into `LlmUsageLog`**

Edit `tests/Feature/Domain/Llm/LlmManagerLoggingTest.php` — add this method (keep the three existing test methods unchanged):

```php
    public function test_complete_persists_response_metadata(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return new class extends FakeLlmProvider
            {
                public function complete(LlmRequest $request): LlmResponse
                {
                    return new LlmResponse(
                        content: '{"ok":true}',
                        provider: 'fake',
                        model: $request->model ?? 'fake-model',
                        promptTokens: 10,
                        completionTokens: 5,
                        metadata: ['finish_reason' => 'stop', 'provider_finish_reason' => 'stop'],
                    );
                }
            };
        });

        $manager = $this->app->make(LlmManagerInterface::class);
        $manager->complete(null, 'script', [['role' => 'user', 'content' => 'hi']]);

        $log = LlmUsageLog::sole();

        $this->assertSame(['finish_reason' => 'stop', 'provider_finish_reason' => 'stop'], $log->metadata);
    }
```

- [ ] **Step 5: Run tests**

```bash
php artisan test --filter=LlmManagerLoggingTest
php artisan test --filter=LlmManagerTest
vendor/bin/pint --test
```

Expected: all pass (existing 3 + 1 new in `LlmManagerLoggingTest`, existing 6 in `LlmManagerTest`), Pint clean. (`php artisan test` at this point requires Postgres up — `docker compose up -d postgres redis` first, and `.env.testing`/`DB_PORT` set per the current `phpunit.xml`, which Task 11 changes; until then, keep using whatever local setup already gets `php artisan test` passing from Phase 1.)

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Llm/LlmRequest.php app/Domain/Llm/LlmManagerInterface.php app/Domain/Llm/LlmManager.php tests/Feature/Domain/Llm/LlmManagerLoggingTest.php
git commit -m "Retype LlmRequest.responseSchema to array and persist LLM response metadata"
```

---

### Task 2: `OpenAiLlmProvider` — JSON Schema structured output, timeout/retry, finish_reason

**Files:**
- Modify: `app/Domain/Llm/Providers/OpenAiLlmProvider.php`
- Modify: `tests/Unit/Domain/Llm/OpenAiLlmProviderTest.php`

**Interfaces:**
- Consumes: `LlmRequest::$responseSchema` as `?array` (Task 1).
- Produces: `LlmResponse::$metadata` populated with `finish_reason`/`provider_finish_reason` for OpenAI calls — Task 5/6 depend on `finish_reason` being present when they read `LlmResponse::$metadata` (not required for the repair loop itself, but part of the spec contract).

- [ ] **Step 1: Update `OpenAiLlmProvider`**

Replace the whole file `app/Domain/Llm/Providers/OpenAiLlmProvider.php`:

```php
<?php

namespace App\Domain\Llm\Providers;

use App\Domain\Llm\LlmProviderInterface;
use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\LlmResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $request->responseSchema['name'],
                    'schema' => $request->responseSchema['schema'],
                    'strict' => $request->responseSchema['strict'] ?? true,
                ],
            ];
        }

        try {
            $response = Http::withToken(config('llm.providers.openai.api_key'))
                ->baseUrl(config('llm.providers.openai.base_url'))
                ->timeout(60)
                ->retry(3, 500, when: fn ($exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()))
                ->post('/chat/completions', $payload);
        } catch (ConnectionException|RequestException $exception) {
            throw new RuntimeException(
                'OpenAI request failed: '.$exception->getMessage(), previous: $exception
            );
        }

        $data = $response->json();
        $finishReason = $data['choices'][0]['finish_reason'] ?? null;

        return new LlmResponse(
            content: $data['choices'][0]['message']['content'] ?? '',
            provider: 'openai',
            model: $data['model'] ?? $payload['model'],
            promptTokens: $data['usage']['prompt_tokens'] ?? 0,
            completionTokens: $data['usage']['completion_tokens'] ?? 0,
            metadata: [
                'finish_reason' => $this->normalizeFinishReason($finishReason),
                'provider_finish_reason' => $finishReason,
            ],
        );
    }

    private function normalizeFinishReason(?string $raw): string
    {
        return match ($raw) {
            'stop' => 'stop',
            'length' => 'length',
            default => 'error',
        };
    }
}
```

- [ ] **Step 2: Replace the test file**

Replace the whole file `tests/Unit/Domain/Llm/OpenAiLlmProviderTest.php`:

```php
<?php

namespace Tests\Unit\Domain\Llm;

use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\Providers\OpenAiLlmProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
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
                    ['message' => ['content' => '{"title":"Hello"}'], 'finish_reason' => 'stop'],
                ],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30],
            ], 200),
        ]);

        $provider = new OpenAiLlmProvider;
        $response = $provider->complete(new LlmRequest(
            purpose: 'script',
            messages: [['role' => 'user', 'content' => 'hi']],
            model: 'gpt-4o-mini',
        ));

        $this->assertSame('{"title":"Hello"}', $response->content);
        $this->assertSame('openai', $response->provider);
        $this->assertSame(120, $response->promptTokens);
        $this->assertSame(30, $response->completionTokens);
        $this->assertSame('stop', $response->metadata['finish_reason']);
        $this->assertSame('stop', $response->metadata['provider_finish_reason']);

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

        $provider = new OpenAiLlmProvider;

        $this->expectException(\RuntimeException::class);

        $provider->complete(new LlmRequest(purpose: 'script', messages: [], model: 'gpt-4o-mini'));
    }

    public function test_it_does_not_retry_on_client_errors(): void
    {
        config()->set('llm.providers.openai.api_key', 'test-key');
        config()->set('llm.providers.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'api.openai.com/*' => Http::response(['error' => 'bad request'], 400),
        ]);

        $provider = new OpenAiLlmProvider;

        try {
            $provider->complete(new LlmRequest(purpose: 'script', messages: [], model: 'gpt-4o-mini'));
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $exception) {
            // expected
        }

        Http::assertSentCount(1);
    }

    public function test_it_retries_on_server_errors_and_eventually_succeeds(): void
    {
        config()->set('llm.providers.openai.api_key', 'test-key');
        config()->set('llm.providers.openai.base_url', 'https://api.openai.com/v1');

        Sleep::fake();

        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push(['error' => 'server error'], 500)
                ->push(['error' => 'server error'], 500)
                ->push([
                    'model' => 'gpt-4o-mini',
                    'choices' => [['message' => ['content' => '{"ok":true}'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                ], 200),
        ]);

        $provider = new OpenAiLlmProvider;
        $response = $provider->complete(new LlmRequest(purpose: 'script', messages: [], model: 'gpt-4o-mini'));

        $this->assertSame('{"ok":true}', $response->content);
        Http::assertSentCount(3);
    }

    public function test_it_requests_structured_output_via_json_schema(): void
    {
        config()->set('llm.providers.openai.api_key', 'test-key');
        config()->set('llm.providers.openai.base_url', 'https://api.openai.com/v1');

        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => '{"title":"Hi"}'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $provider = new OpenAiLlmProvider;
        $response = $provider->complete(new LlmRequest(
            purpose: 'script',
            messages: [['role' => 'user', 'content' => 'hi']],
            responseSchema: [
                'name' => 'video_script',
                'schema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]],
            ],
            model: 'gpt-4o-mini',
        ));

        $this->assertSame('{"title":"Hi"}', $response->content);

        Http::assertSent(function ($request) {
            return $request['response_format']['type'] === 'json_schema'
                && $request['response_format']['json_schema']['name'] === 'video_script'
                && $request['response_format']['json_schema']['strict'] === true;
        });
    }
}
```

- [ ] **Step 3: Run tests**

```bash
php artisan test --filter=OpenAiLlmProviderTest
vendor/bin/pint --test
```

Expected: 5 tests pass, Pint clean.

- [ ] **Step 4: Commit**

```bash
git add app/Domain/Llm/Providers/OpenAiLlmProvider.php tests/Unit/Domain/Llm/OpenAiLlmProviderTest.php
git commit -m "OpenAiLlmProvider: JSON Schema structured output, timeout/retry, finish_reason"
```

---

### Task 3: `AnthropicLlmProvider` — forced tool-use structured output, timeout/retry, finish_reason

**Files:**
- Modify: `app/Domain/Llm/Providers/AnthropicLlmProvider.php`
- Modify: `tests/Unit/Domain/Llm/AnthropicLlmProviderTest.php`

**Interfaces:**
- Consumes: `LlmRequest::$responseSchema` as `?array` (Task 1).
- Produces: same `LlmResponse::$metadata` contract as Task 2, for the Anthropic path. `LlmResponse::$content` is always a raw JSON string when `responseSchema` was set, whether the block Anthropic returns is `tool_use` or (for non-schema calls) plain `text` — Task 5/6 rely on this uniformity to `json_decode()` without branching per provider.

- [ ] **Step 1: Update `AnthropicLlmProvider`**

Replace the whole file `app/Domain/Llm/Providers/AnthropicLlmProvider.php`:

```php
<?php

namespace App\Domain\Llm\Providers;

use App\Domain\Llm\LlmProviderInterface;
use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\LlmResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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

        if ($request->responseSchema !== null) {
            $payload['tools'] = [[
                'name' => $request->responseSchema['name'],
                'input_schema' => $request->responseSchema['schema'],
            ]];
            $payload['tool_choice'] = ['type' => 'tool', 'name' => $request->responseSchema['name']];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => config('llm.providers.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
            ])
                ->baseUrl(config('llm.providers.anthropic.base_url'))
                ->timeout(60)
                ->retry(3, 500, when: fn ($exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()))
                ->post('/messages', $payload);
        } catch (ConnectionException|RequestException $exception) {
            throw new RuntimeException(
                'Anthropic request failed: '.$exception->getMessage(), previous: $exception
            );
        }

        $data = $response->json();
        $stopReason = $data['stop_reason'] ?? null;

        return new LlmResponse(
            content: $this->extractContent($data['content'] ?? [], $request->responseSchema !== null),
            provider: 'anthropic',
            model: $data['model'] ?? $payload['model'],
            promptTokens: $data['usage']['input_tokens'] ?? 0,
            completionTokens: $data['usage']['output_tokens'] ?? 0,
            metadata: [
                'finish_reason' => $this->normalizeFinishReason($stopReason),
                'provider_finish_reason' => $stopReason,
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function extractContent(array $blocks, bool $expectsToolUse): string
    {
        if ($expectsToolUse) {
            foreach ($blocks as $block) {
                if (($block['type'] ?? null) === 'tool_use') {
                    return json_encode($block['input'] ?? [], JSON_THROW_ON_ERROR);
                }
            }

            return '';
        }

        return $blocks[0]['text'] ?? '';
    }

    private function normalizeFinishReason(?string $raw): string
    {
        return match ($raw) {
            'end_turn', 'tool_use', 'stop_sequence' => 'stop',
            'max_tokens' => 'length',
            default => 'error',
        };
    }
}
```

- [ ] **Step 2: Replace the test file**

Replace the whole file `tests/Unit/Domain/Llm/AnthropicLlmProviderTest.php`:

```php
<?php

namespace Tests\Unit\Domain\Llm;

use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\Providers\AnthropicLlmProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
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
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => '{"title":"Hello"}']],
                'usage' => ['input_tokens' => 80, 'output_tokens' => 20],
            ], 200),
        ]);

        $provider = new AnthropicLlmProvider;
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
        $this->assertSame('stop', $response->metadata['finish_reason']);
        $this->assertSame('end_turn', $response->metadata['provider_finish_reason']);

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

        $provider = new AnthropicLlmProvider;

        $this->expectException(\RuntimeException::class);

        $provider->complete(new LlmRequest(purpose: 'script', messages: [], model: 'claude-haiku-4.5'));
    }

    public function test_it_retries_on_server_errors_and_eventually_succeeds(): void
    {
        config()->set('llm.providers.anthropic.api_key', 'test-key');
        config()->set('llm.providers.anthropic.base_url', 'https://api.anthropic.com/v1');

        Sleep::fake();

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['error' => 'overloaded'], 529)
                ->push(['error' => 'overloaded'], 529)
                ->push([
                    'model' => 'claude-haiku-4.5',
                    'stop_reason' => 'end_turn',
                    'content' => [['type' => 'text', 'text' => '{"ok":true}']],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ], 200),
        ]);

        $provider = new AnthropicLlmProvider;
        $response = $provider->complete(new LlmRequest(purpose: 'script', messages: [], model: 'claude-haiku-4.5'));

        $this->assertSame('{"ok":true}', $response->content);
        Http::assertSentCount(3);
    }

    public function test_it_extracts_structured_output_from_forced_tool_use(): void
    {
        config()->set('llm.providers.anthropic.api_key', 'test-key');
        config()->set('llm.providers.anthropic.base_url', 'https://api.anthropic.com/v1');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-opus-4',
                'stop_reason' => 'tool_use',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'video_script',
                    'input' => [
                        'title' => 'Hi', 'hook' => 'H', 'script' => 'S',
                        'estimated_duration' => 60, 'cta' => 'C',
                    ],
                ]],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 40],
            ], 200),
        ]);

        $provider = new AnthropicLlmProvider;
        $response = $provider->complete(new LlmRequest(
            purpose: 'script',
            messages: [['role' => 'user', 'content' => 'hi']],
            responseSchema: [
                'name' => 'video_script',
                'schema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]],
            ],
            model: 'claude-opus-4',
        ));

        $this->assertSame(
            ['title' => 'Hi', 'hook' => 'H', 'script' => 'S', 'estimated_duration' => 60, 'cta' => 'C'],
            json_decode($response->content, true)
        );
        $this->assertSame('stop', $response->metadata['finish_reason']);
        $this->assertSame('tool_use', $response->metadata['provider_finish_reason']);

        Http::assertSent(function ($request) {
            return $request['tool_choice'] === ['type' => 'tool', 'name' => 'video_script']
                && $request['tools'][0]['name'] === 'video_script';
        });
    }
}
```

- [ ] **Step 3: Run tests**

```bash
php artisan test --filter=AnthropicLlmProviderTest
vendor/bin/pint --test
```

Expected: 4 tests pass, Pint clean.

- [ ] **Step 4: Commit**

```bash
git add app/Domain/Llm/Providers/AnthropicLlmProvider.php tests/Unit/Domain/Llm/AnthropicLlmProviderTest.php
git commit -m "AnthropicLlmProvider: forced tool-use structured output, timeout/retry, finish_reason"
```

---

### Task 4: `ScriptStatus` enum

**Files:**
- Create: `app/Models/Enums/ScriptStatus.php`
- Modify: `app/Models/Script.php`
- Modify: `database/factories/ScriptFactory.php`
- Modify: `tests/Feature/ContentDomainModelsTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `App\Models\Enums\ScriptStatus` (`Pending|Processing|Completed|Failed`), cast on `Script::$status` — Task 7's `GenerateScriptJob` assigns these cases directly.

- [ ] **Step 1: Create the enum**

Create `app/Models/Enums/ScriptStatus.php`:

```php
<?php

namespace App\Models\Enums;

enum ScriptStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
```

- [ ] **Step 2: Cast it on `Script`**

Edit `app/Models/Script.php` — add the import and the cast:

```php
<?php

namespace App\Models;

use App\Models\Enums\ScriptStatus;
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
            'status' => ScriptStatus::class,
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

- [ ] **Step 3: Update the factory default**

Edit `database/factories/ScriptFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ContentIdea;
use App\Models\Enums\ScriptStatus;
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
            'status' => ScriptStatus::Completed,
        ];
    }
}
```

- [ ] **Step 4: Add a cast test**

Edit `tests/Feature/ContentDomainModelsTest.php` — add the import and this test method (keep the four existing tests unchanged):

```php
use App\Models\Enums\ScriptStatus;
```

```php
    public function test_script_status_casts_to_enum(): void
    {
        $script = Script::factory()->create(['status' => ScriptStatus::Failed]);

        $this->assertSame(ScriptStatus::Failed, $script->fresh()->status);
    }
```

- [ ] **Step 5: Run migrations (no schema change, just refresh factories) and tests**

```bash
php artisan test --filter=ContentDomainModelsTest
vendor/bin/pint --test
```

Expected: 5 tests pass (4 existing + 1 new), Pint clean.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Enums/ScriptStatus.php app/Models/Script.php database/factories/ScriptFactory.php tests/Feature/ContentDomainModelsTest.php
git commit -m "Add ScriptStatus enum and cast it on Script"
```

---

### Task 5: `GenerateScriptService` — structured output with repair loop

**Files:**
- Create: `app/Domain/Content/Exceptions/ScriptGenerationFailedException.php`
- Create: `app/Domain/Content/Services/GenerateScriptService.php`
- Test: `tests/Feature/Domain/Content/GenerateScriptServiceTest.php`

**Interfaces:**
- Consumes: `LlmManagerInterface::complete()` (Task 1), `App\Models\ContentIdea` (Phase 1, `belongsTo` `ContentProject`), `App\Domain\Llm\ResolvedLlmTarget` (Phase 1).
- Produces: `App\Domain\Content\Services\GenerateScriptService::generate(ContentIdea $idea, ResolvedLlmTarget $target): array{title: string, hook: string, script: string, estimated_duration: int, cta: string}`, throwing `App\Domain\Content\Exceptions\ScriptGenerationFailedException` after 3 total attempts. Task 7's `GenerateScriptJob` calls this exact signature and maps the returned array onto `Script` columns.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Domain/Content/GenerateScriptServiceTest.php`:

```php
<?php

namespace Tests\Feature\Domain\Content;

use App\Domain\Content\Exceptions\ScriptGenerationFailedException;
use App\Domain\Content\Services\GenerateScriptService;
use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Llm\LlmResponse;
use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Domain\Llm\ResolvedLlmTarget;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateScriptServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_parsed_script_on_a_valid_first_response(): void
    {
        $idea = ContentIdea::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $manager = $this->queuedLlmManager([
            '{"title":"T","hook":"H","script":"S","estimated_duration":42,"cta":"C"}',
        ]);

        $service = new GenerateScriptService($manager);
        $result = $service->generate($idea, $target);

        $this->assertSame([
            'title' => 'T', 'hook' => 'H', 'script' => 'S', 'estimated_duration' => 42, 'cta' => 'C',
        ], $result);
    }

    public function test_it_repairs_after_one_invalid_response(): void
    {
        $idea = ContentIdea::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $manager = $this->queuedLlmManager([
            'not json at all',
            '{"title":"T","hook":"H","script":"S","estimated_duration":42,"cta":"C"}',
        ]);

        $service = new GenerateScriptService($manager);
        $result = $service->generate($idea, $target);

        $this->assertSame(42, $result['estimated_duration']);
    }

    public function test_it_throws_after_exhausting_repair_attempts(): void
    {
        $idea = ContentIdea::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $manager = $this->queuedLlmManager([
            'not json',
            'still not json',
            'nope',
        ]);

        $service = new GenerateScriptService($manager);

        $this->expectException(ScriptGenerationFailedException::class);

        $service->generate($idea, $target);
    }

    public function test_it_rejects_a_response_missing_a_required_field(): void
    {
        $idea = ContentIdea::factory()->create();
        $target = new ResolvedLlmTarget(new FakeLlmProvider, 'fake', 'fake-model');

        $manager = $this->queuedLlmManager([
            '{"title":"T","hook":"H","script":"S","cta":"C"}',
            '{"title":"T","hook":"H","script":"S","cta":"C"}',
            '{"title":"T","hook":"H","script":"S","cta":"C"}',
        ]);

        $service = new GenerateScriptService($manager);

        $this->expectException(ScriptGenerationFailedException::class);

        $service->generate($idea, $target);
    }

    /**
     * @param  array<int, string>  $responses
     */
    private function queuedLlmManager(array $responses): LlmManagerInterface
    {
        return new class($responses) implements LlmManagerInterface
        {
            private int $index = 0;

            /** @param array<int, string> $responses */
            public function __construct(private array $responses) {}

            public function resolve(?ContentProject $project, string $purpose, ?string $providerOverride = null, ?string $modelOverride = null): ResolvedLlmTarget
            {
                throw new \LogicException('Not used in this test.');
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
        };
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=GenerateScriptServiceTest
```

Expected: FAIL — `App\Domain\Content\Services\GenerateScriptService` and `App\Domain\Content\Exceptions\ScriptGenerationFailedException` don't exist yet.

- [ ] **Step 3: Create the exception**

Create `app/Domain/Content/Exceptions/ScriptGenerationFailedException.php`:

```php
<?php

namespace App\Domain\Content\Exceptions;

use RuntimeException;

final class ScriptGenerationFailedException extends RuntimeException {}
```

- [ ] **Step 4: Implement `GenerateScriptService`**

Create `app/Domain/Content/Services/GenerateScriptService.php`:

```php
<?php

namespace App\Domain\Content\Services;

use App\Domain\Content\Exceptions\ScriptGenerationFailedException;
use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Llm\ResolvedLlmTarget;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use InvalidArgumentException;
use JsonException;

final class GenerateScriptService
{
    private const MAX_REPAIR_ATTEMPTS = 2;

    public function __construct(private readonly LlmManagerInterface $llmManager) {}

    /**
     * @return array{title: string, hook: string, script: string, estimated_duration: int, cta: string}
     */
    public function generate(ContentIdea $idea, ResolvedLlmTarget $target): array
    {
        $project = $idea->contentProject;
        $messages = $this->buildMessages($idea, $project);
        $lastError = 'unknown validation error';

        for ($attempt = 0; $attempt <= self::MAX_REPAIR_ATTEMPTS; $attempt++) {
            $response = $this->llmManager->complete(
                project: $project,
                purpose: 'script',
                messages: $messages,
                responseSchema: $this->schema(),
                providerOverride: $target->providerName,
                modelOverride: $target->model,
            );

            try {
                return $this->parse($response->content);
            } catch (JsonException|InvalidArgumentException $exception) {
                $lastError = $exception->getMessage();
                $messages[] = ['role' => 'assistant', 'content' => $response->content];
                $messages[] = [
                    'role' => 'user',
                    'content' => "Invalid response: {$lastError}. Reply again with valid JSON matching the schema exactly.",
                ];
            }
        }

        throw new ScriptGenerationFailedException($lastError);
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(ContentIdea $idea, ?ContentProject $project): array
    {
        $settings = $project?->settings ?? [];

        $system = sprintf(
            'You are a scriptwriter for short vertical videos. Niche: %s. Language: %s. Tone: %s. Style: %s. '
            .'Respond only with JSON matching the given schema — no prose outside the JSON.',
            $project?->niche ?? 'general',
            $project?->language ?? 'en',
            $settings['tone'] ?? 'neutral',
            $settings['style'] ?? 'informational',
        );

        $user = sprintf(
            "Write a short-form video script for this idea.\nTitle: %s\nTopic: %s",
            $idea->title,
            $idea->topic,
        );

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * @return array{name: string, schema: array<string, mixed>, strict: bool}
     */
    private function schema(): array
    {
        return [
            'name' => 'video_script',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'hook' => ['type' => 'string'],
                    'script' => ['type' => 'string'],
                    'estimated_duration' => ['type' => 'integer'],
                    'cta' => ['type' => 'string'],
                ],
                'required' => ['title', 'hook', 'script', 'estimated_duration', 'cta'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ];
    }

    /**
     * @return array{title: string, hook: string, script: string, estimated_duration: int, cta: string}
     */
    private function parse(string $content): array
    {
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new InvalidArgumentException('Response is not a JSON object.');
        }

        foreach (['title', 'hook', 'script', 'estimated_duration', 'cta'] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidArgumentException("Missing required field [{$field}].");
            }
        }

        if (! is_int($data['estimated_duration'])) {
            throw new InvalidArgumentException('Field [estimated_duration] must be an integer.');
        }

        foreach (['title', 'hook', 'script', 'cta'] as $field) {
            if (! is_string($data[$field])) {
                throw new InvalidArgumentException("Field [{$field}] must be a string.");
            }
        }

        return [
            'title' => $data['title'],
            'hook' => $data['hook'],
            'script' => $data['script'],
            'estimated_duration' => $data['estimated_duration'],
            'cta' => $data['cta'],
        ];
    }
}
```

- [ ] **Step 5: Run tests**

```bash
php artisan test --filter=GenerateScriptServiceTest
vendor/bin/pint --test
```

Expected: 4 tests pass, Pint clean.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Content/Exceptions/ScriptGenerationFailedException.php app/Domain/Content/Services/GenerateScriptService.php tests/Feature/Domain/Content/GenerateScriptServiceTest.php
git commit -m "Add GenerateScriptService with JSON Schema output and a repair loop"
```

---

### Task 6: `GenerateContentIdeaService`

**Files:**
- Create: `app/Domain/Content/Services/GenerateContentIdeaService.php`
- Test: `tests/Feature/Domain/Content/GenerateContentIdeaServiceTest.php`

**Interfaces:**
- Consumes: `LlmManagerInterface::complete()` (Task 1), `App\Models\ContentProject`, `App\Models\ContentIdea`, `App\Models\Enums\ContentIdeaStatus` (all Phase 1).
- Produces: `App\Domain\Content\Services\GenerateContentIdeaService::generate(ContentProject $project, string $topic): ContentIdea` — Task 9's Filament action calls this exact signature.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Domain/Content/GenerateContentIdeaServiceTest.php`:

```php
<?php

namespace Tests\Feature\Domain\Content;

use App\Domain\Content\Services\GenerateContentIdeaService;
use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateContentIdeaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_content_idea_from_the_llm_response(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['title' => 'AI news roundup', 'topic' => 'ai news', 'score' => 82.5])
            );
        });

        $project = ContentProject::factory()->create(['settings' => []]);

        $service = $this->app->make(GenerateContentIdeaService::class);
        $idea = $service->generate($project, 'ai news');

        $this->assertDatabaseHas('content_ideas', [
            'id' => $idea->id,
            'content_project_id' => $project->id,
            'title' => 'AI news roundup',
            'topic' => 'ai news',
            'source' => 'ai_generated',
        ]);
        $this->assertSame(ContentIdeaStatus::New, $idea->fresh()->status);
        $this->assertSame(82.5, $idea->score);
    }

    public function test_it_throws_when_the_llm_response_is_not_valid_json(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith('not json');
        });

        $project = ContentProject::factory()->create(['settings' => []]);
        $service = $this->app->make(GenerateContentIdeaService::class);

        $this->expectException(\JsonException::class);

        $service->generate($project, 'ai news');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=GenerateContentIdeaServiceTest
```

Expected: FAIL — `GenerateContentIdeaService` doesn't exist yet.

- [ ] **Step 3: Implement `GenerateContentIdeaService`**

Create `app/Domain/Content/Services/GenerateContentIdeaService.php`:

```php
<?php

namespace App\Domain\Content\Services;

use App\Domain\Llm\LlmManagerInterface;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use InvalidArgumentException;

final class GenerateContentIdeaService
{
    public function __construct(private readonly LlmManagerInterface $llmManager) {}

    public function generate(ContentProject $project, string $topic): ContentIdea
    {
        $response = $this->llmManager->complete(
            project: $project,
            purpose: 'idea',
            messages: $this->buildMessages($project, $topic),
            responseSchema: $this->schema(),
        );

        $data = $this->parse($response->content);

        return ContentIdea::create([
            'content_project_id' => $project->id,
            'title' => $data['title'],
            'topic' => $data['topic'],
            'source' => 'ai_generated',
            'source_data' => $data,
            'score' => $data['score'],
            'status' => ContentIdeaStatus::New,
        ]);
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(ContentProject $project, string $topic): array
    {
        $system = sprintf(
            'You are a content strategist for short vertical videos. Niche: %s. Language: %s. '
            .'Respond only with JSON matching the given schema — no prose outside the JSON.',
            $project->niche,
            $project->language,
        );

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "Propose one video idea about: {$topic}"],
        ];
    }

    /**
     * @return array{name: string, schema: array<string, mixed>, strict: bool}
     */
    private function schema(): array
    {
        return [
            'name' => 'content_idea',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'topic' => ['type' => 'string'],
                    'score' => ['type' => ['number', 'null']],
                ],
                'required' => ['title', 'topic', 'score'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ];
    }

    /**
     * @return array{title: string, topic: string, score: float|null}
     */
    private function parse(string $content): array
    {
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! isset($data['title'], $data['topic'])
            || ! is_string($data['title']) || ! is_string($data['topic'])) {
            throw new InvalidArgumentException('Idea response is missing required string fields [title, topic].');
        }

        $score = $data['score'] ?? null;
        if ($score !== null && ! is_numeric($score)) {
            throw new InvalidArgumentException('Field [score] must be numeric or null.');
        }

        return [
            'title' => $data['title'],
            'topic' => $data['topic'],
            'score' => $score !== null ? (float) $score : null,
        ];
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --filter=GenerateContentIdeaServiceTest
vendor/bin/pint --test
```

Expected: 2 tests pass, Pint clean.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Content/Services/GenerateContentIdeaService.php tests/Feature/Domain/Content/GenerateContentIdeaServiceTest.php
git commit -m "Add GenerateContentIdeaService"
```

---

### Task 7: `GenerateScriptJob`

**Files:**
- Create: `app/Jobs/GenerateScriptJob.php`
- Test: `tests/Feature/Jobs/GenerateScriptJobTest.php`

**Interfaces:**
- Consumes: `App\Domain\Content\Services\GenerateScriptService::generate()` (Task 5), `LlmManagerInterface::resolve()` (Phase 1), `App\Models\Script`/`ScriptStatus` (Task 4), `App\Models\ContentIdea`/`ContentIdeaStatus` (Phase 1).
- Produces: `App\Jobs\GenerateScriptJob` with public constructor `__construct(public readonly int $contentIdeaId)` — Task 9's Filament row action dispatches `GenerateScriptJob::dispatch($record->id)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Jobs/GenerateScriptJobTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Jobs\GenerateScriptJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use App\Models\Enums\ScriptStatus;
use App\Models\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateScriptJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_completed_script_and_marks_the_idea_used(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['title' => 'T', 'hook' => 'H', 'script' => 'Body', 'estimated_duration' => 55, 'cta' => 'Follow'])
            );
        });

        $idea = ContentIdea::factory()->create([
            'status' => ContentIdeaStatus::Approved,
            'content_project_id' => ContentProject::factory()->create(['settings' => []]),
        ]);

        $job = new GenerateScriptJob($idea->id);
        app()->call([$job, 'handle']);

        $idea->refresh();
        $this->assertSame(ContentIdeaStatus::Used, $idea->status);

        $script = Script::where('content_idea_id', $idea->id)->sole();
        $this->assertSame(ScriptStatus::Completed, $script->status);
        $this->assertSame('Body', $script->content);
        $this->assertSame('H', $script->hook);
        $this->assertSame(55, $script->estimated_duration);
        $this->assertSame('Follow', $script->metadata['cta']);
    }

    public function test_it_is_a_no_op_when_the_idea_is_not_approved(): void
    {
        $idea = ContentIdea::factory()->create(['status' => ContentIdeaStatus::New]);

        $job = new GenerateScriptJob($idea->id);
        app()->call([$job, 'handle']);

        $this->assertDatabaseCount('scripts', 0);
        $this->assertSame(ContentIdeaStatus::New, $idea->fresh()->status);
    }

    public function test_calling_handle_twice_does_not_create_a_duplicate_script(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['title' => 'T', 'hook' => 'H', 'script' => 'Body', 'estimated_duration' => 55, 'cta' => 'Follow'])
            );
        });

        $idea = ContentIdea::factory()->create(['status' => ContentIdeaStatus::Approved]);

        app()->call([new GenerateScriptJob($idea->id), 'handle']);
        app()->call([new GenerateScriptJob($idea->id), 'handle']);

        $this->assertSame(1, Script::where('content_idea_id', $idea->id)->count());
    }

    public function test_a_second_handle_call_while_the_idea_is_still_processing_reuses_the_same_script_row(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $idea = ContentIdea::factory()->create(['status' => ContentIdeaStatus::Processing]);

        Script::factory()->create([
            'content_idea_id' => $idea->id,
            'status' => ScriptStatus::Processing,
            'content' => null,
        ]);

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['title' => 'T', 'hook' => 'H', 'script' => 'Body', 'estimated_duration' => 55, 'cta' => 'Follow'])
            );
        });

        app()->call([new GenerateScriptJob($idea->id), 'handle']);

        $this->assertSame(1, Script::where('content_idea_id', $idea->id)->count());
        $this->assertSame(ScriptStatus::Completed, Script::where('content_idea_id', $idea->id)->sole()->status);
    }

    public function test_failed_marks_the_script_failed_and_reverts_the_idea_to_approved(): void
    {
        $idea = ContentIdea::factory()->create(['status' => ContentIdeaStatus::Processing]);
        $script = Script::factory()->create([
            'content_idea_id' => $idea->id,
            'status' => ScriptStatus::Processing,
            'content' => null,
        ]);

        $job = new GenerateScriptJob($idea->id);
        $job->failed(new \RuntimeException('LLM unavailable'));

        $this->assertSame(ScriptStatus::Failed, $script->fresh()->status);
        $this->assertSame('LLM unavailable', $script->fresh()->metadata['error']);
        $this->assertSame(ContentIdeaStatus::Approved, $idea->fresh()->status);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=GenerateScriptJobTest
```

Expected: FAIL — `App\Jobs\GenerateScriptJob` doesn't exist yet.

- [ ] **Step 3: Implement `GenerateScriptJob`**

Create `app/Jobs/GenerateScriptJob.php`:

```php
<?php

namespace App\Jobs;

use App\Domain\Content\Services\GenerateScriptService;
use App\Domain\Llm\LlmManagerInterface;
use App\Models\ContentIdea;
use App\Models\Enums\ContentIdeaStatus;
use App\Models\Enums\ScriptStatus;
use App\Models\Script;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateScriptJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 180;

    public int $tries = 3;

    public int $uniqueFor = 200;

    public function __construct(public readonly int $contentIdeaId) {}

    public function uniqueId(): string
    {
        return (string) $this->contentIdeaId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(LlmManagerInterface $llmManager, GenerateScriptService $service): void
    {
        $idea = ContentIdea::findOrFail($this->contentIdeaId);

        if (! in_array($idea->status, [ContentIdeaStatus::Approved, ContentIdeaStatus::Processing], true)) {
            return;
        }

        if ($idea->status !== ContentIdeaStatus::Processing) {
            $idea->update(['status' => ContentIdeaStatus::Processing]);
        }

        $target = $llmManager->resolve($idea->contentProject, 'script');

        $script = Script::firstOrCreate(
            ['content_idea_id' => $idea->id],
            [
                'provider' => $target->providerName,
                'model' => $target->model,
                'prompt_version' => 'v1',
                'status' => ScriptStatus::Pending,
            ]
        );

        $script->update(['status' => ScriptStatus::Processing]);

        $data = $service->generate($idea, $target);

        $script->update([
            'content' => $data['script'],
            'hook' => $data['hook'],
            'estimated_duration' => $data['estimated_duration'],
            'metadata' => ['title' => $data['title'], 'cta' => $data['cta']],
            'status' => ScriptStatus::Completed,
        ]);

        $idea->update(['status' => ContentIdeaStatus::Used]);
    }

    public function failed(Throwable $exception): void
    {
        $idea = ContentIdea::find($this->contentIdeaId);

        if ($idea === null) {
            return;
        }

        $script = Script::where('content_idea_id', $idea->id)->first();

        if ($script !== null) {
            $script->update([
                'status' => ScriptStatus::Failed,
                'metadata' => array_merge($script->metadata ?? [], ['error' => $exception->getMessage()]),
            ]);
        }

        if ($idea->status === ContentIdeaStatus::Processing) {
            $idea->update(['status' => ContentIdeaStatus::Approved]);
        }

        Log::channel('content')->error('Script generation failed permanently.', [
            'content_idea_id' => $idea->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --filter=GenerateScriptJobTest
vendor/bin/pint --test
```

Expected: 5 tests pass, Pint clean.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/GenerateScriptJob.php tests/Feature/Jobs/GenerateScriptJobTest.php
git commit -m "Add idempotent GenerateScriptJob"
```

---

### Task 8: Filament — structured `settings.ai.*` fields on `ContentProjectForm`

**Files:**
- Modify: `app/Filament/Resources/ContentProjects/Schemas/ContentProjectForm.php`
- Modify: `app/Filament/Resources/ContentProjects/Pages/EditContentProject.php`
- Test: `tests/Feature/Filament/ContentProjectAiSettingsTest.php`

**Interfaces:**
- Consumes: `config('llm.providers')` (Phase 1's `config/llm.php`).
- Produces: editable `Select`/`TextInput` fields at `settings.ai.{default,idea,script,quality_check,captions}.{provider,model}` on the `ContentProject` create/edit forms — this is what lets a user actually set `ContentProject.settings.ai.script` from the admin panel (the Phase 2 DoD's "провайдер/модель беруться з `ContentProject.settings.ai.script`, а не хардкодяться").

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/ContentProjectAiSettingsTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ContentProjects\Pages\EditContentProject;
use App\Models\ContentProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContentProjectAiSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_ai_settings_preserves_other_settings_keys(): void
    {
        $this->actingAs(User::factory()->create());

        $project = ContentProject::factory()->create([
            'settings' => [
                'tone' => 'fast',
                'target_duration' => 60,
                'ai' => [
                    'default' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
                ],
            ],
        ]);

        Livewire::test(EditContentProject::class, ['record' => $project->getRouteKey()])
            ->fillForm([
                'settings.ai.script.provider' => 'anthropic',
                'settings.ai.script.model' => 'claude-opus-4',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $project->refresh();

        $this->assertSame('fast', $project->settings['tone']);
        $this->assertSame(60, $project->settings['target_duration']);
        $this->assertSame('openai', $project->settings['ai']['default']['provider']);
        $this->assertSame('anthropic', $project->settings['ai']['script']['provider']);
        $this->assertSame('claude-opus-4', $project->settings['ai']['script']['model']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=ContentProjectAiSettingsTest
```

Expected: FAIL — the `settings.ai.script.*` fields don't exist on the form yet, so the submitted keys never reach `$project->settings`.

- [ ] **Step 3: Replace `ContentProjectForm`**

Replace the whole file `app/Filament/Resources/ContentProjects/Schemas/ContentProjectForm.php`:

```php
<?php

namespace App\Filament\Resources\ContentProjects\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ContentProjectForm
{
    private const PURPOSES = [
        'default' => 'Default',
        'idea' => 'Idea generation',
        'script' => 'Script generation',
        'quality_check' => 'Quality check',
        'captions' => 'Captions/hashtags',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('niche')
                    ->required(),
                TextInput::make('language')
                    ->required(),
                TextInput::make('target_platforms')
                    ->required()
                    ->default('[]')
                    ->disabled(),
                TextInput::make('status')
                    ->required(),
                ...static::aiSettingsFields(),
            ]);
    }

    /**
     * @return array<int, \Filament\Forms\Components\Select|\Filament\Forms\Components\TextInput>
     */
    private static function aiSettingsFields(): array
    {
        $providers = array_diff(array_keys(config('llm.providers')), ['fake', 'fake_secondary']);
        $providerOptions = array_combine($providers, $providers);

        $fields = [];

        foreach (self::PURPOSES as $purpose => $label) {
            $fields[] = Select::make("settings.ai.{$purpose}.provider")
                ->label("{$label} — provider")
                ->options($providerOptions);

            $fields[] = TextInput::make("settings.ai.{$purpose}.model")
                ->label("{$label} — model");
        }

        return $fields;
    }
}
```

- [ ] **Step 4: Preserve non-`ai` settings keys on edit**

Edit `app/Filament/Resources/ContentProjects/Pages/EditContentProject.php` — add the `mutateFormDataBeforeSave` override:

```php
<?php

namespace App\Filament\Resources\ContentProjects\Pages;

use App\Filament\Resources\ContentProjects\ContentProjectResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditContentProject extends EditRecord
{
    protected static string $resource = ContentProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['settings'] = array_replace_recursive($this->record->settings ?? [], $data['settings'] ?? []);

        return $data;
    }
}
```

(Without this, saving the edit form would silently drop `tone`/`target_duration`/any other top-level `settings` key that has no corresponding form field — the form only produces `settings.ai.*`.)

- [ ] **Step 5: Run tests**

```bash
php artisan test --filter=ContentProjectAiSettingsTest
vendor/bin/pint --test
```

Expected: 1 test passes, Pint clean.

- [ ] **Step 6: Run the full existing Filament/model suite to check for regressions**

```bash
php artisan test --filter=FilamentResourcesTest
php artisan test --filter=ContentDomainModelsTest
```

Expected: still green (the `ContentProjectFactory` default `settings.ai.default` shape from Phase 1 is unaffected by the form change).

- [ ] **Step 7: Commit**

```bash
git add app/Filament/Resources/ContentProjects/Schemas/ContentProjectForm.php app/Filament/Resources/ContentProjects/Pages/EditContentProject.php tests/Feature/Filament/ContentProjectAiSettingsTest.php
git commit -m "Filament: structured per-purpose ai settings fields on ContentProject"
```

---

### Task 9: Filament — `ContentIdeaResource` actions (Generate Idea, Approve, Reject, Generate Script)

**Files:**
- Modify: `app/Filament/Resources/ContentIdeas/Pages/ListContentIdeas.php`
- Modify: `app/Filament/Resources/ContentIdeas/Tables/ContentIdeasTable.php`
- Test: `tests/Feature/Filament/ContentIdeaActionsTest.php`

**Interfaces:**
- Consumes: `GenerateContentIdeaService::generate()` (Task 6), `GenerateScriptJob::dispatch()` (Task 7), `App\Models\Enums\ContentIdeaStatus` (Phase 1).
- Produces: an admin-panel-reachable "Generate Idea" header action and "Approve"/"Reject"/"Generate Script" row actions — nothing downstream depends on this beyond the DoD itself.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/ContentIdeaActionsTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Filament\Resources\ContentIdeas\Pages\ListContentIdeas;
use App\Jobs\GenerateScriptJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ContentIdeaActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_idea_action_creates_a_content_idea(): void
    {
        $this->actingAs(User::factory()->create());

        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['title' => 'T', 'topic' => 'ai', 'score' => 50])
            );
        });

        $project = ContentProject::factory()->create(['settings' => []]);

        Livewire::test(ListContentIdeas::class)
            ->callAction('generateIdea', data: [
                'content_project_id' => $project->id,
                'topic' => 'ai',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('content_ideas', [
            'content_project_id' => $project->id,
            'title' => 'T',
        ]);
    }

    public function test_approve_action_transitions_new_idea_to_approved(): void
    {
        $this->actingAs(User::factory()->create());

        $idea = ContentIdea::factory()->create(['status' => ContentIdeaStatus::New]);

        Livewire::test(ListContentIdeas::class)
            ->callTableAction('approve', $idea)
            ->assertHasNoTableActionErrors();

        $this->assertSame(ContentIdeaStatus::Approved, $idea->fresh()->status);
    }

    public function test_reject_action_transitions_new_idea_to_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        $idea = ContentIdea::factory()->create(['status' => ContentIdeaStatus::New]);

        Livewire::test(ListContentIdeas::class)
            ->callTableAction('reject', $idea)
            ->assertHasNoTableActionErrors();

        $this->assertSame(ContentIdeaStatus::Rejected, $idea->fresh()->status);
    }

    public function test_generate_script_action_dispatches_the_job_only_when_approved(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        $idea = ContentIdea::factory()->create(['status' => ContentIdeaStatus::Approved]);

        Livewire::test(ListContentIdeas::class)
            ->callTableAction('generateScript', $idea)
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(GenerateScriptJob::class, fn (GenerateScriptJob $job) => $job->contentIdeaId === $idea->id);
    }

    public function test_generate_script_action_is_not_visible_for_a_new_idea(): void
    {
        $this->actingAs(User::factory()->create());

        $idea = ContentIdea::factory()->create(['status' => ContentIdeaStatus::New]);

        Livewire::test(ListContentIdeas::class)
            ->assertTableActionHidden('generateScript', $idea);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=ContentIdeaActionsTest
```

Expected: FAIL — none of these actions exist on the resource yet.

- [ ] **Step 3: Add the "Generate Idea" header action**

Replace the whole file `app/Filament/Resources/ContentIdeas/Pages/ListContentIdeas.php`:

```php
<?php

namespace App\Filament\Resources\ContentIdeas\Pages;

use App\Domain\Content\Services\GenerateContentIdeaService;
use App\Filament\Resources\ContentIdeas\ContentIdeaResource;
use App\Models\ContentProject;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

class ListContentIdeas extends ListRecords
{
    protected static string $resource = ContentIdeaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateIdea')
                ->label('Generate Idea')
                ->schema([
                    Select::make('content_project_id')
                        ->label('Content Project')
                        ->options(fn (): array => ContentProject::query()->pluck('name', 'id')->all())
                        ->required(),
                    TextInput::make('topic')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(GenerateContentIdeaService::class)->generate(
                            ContentProject::findOrFail($data['content_project_id']),
                            $data['topic'],
                        );

                        Notification::make()->title('Idea generated')->success()->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Idea generation failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            CreateAction::make(),
        ];
    }
}
```

- [ ] **Step 4: Add the "Approve"/"Reject"/"Generate Script" row actions**

Replace the whole file `app/Filament/Resources/ContentIdeas/Tables/ContentIdeasTable.php`:

```php
<?php

namespace App\Filament\Resources\ContentIdeas\Tables;

use App\Jobs\GenerateScriptJob;
use App\Models\ContentIdea;
use App\Models\Enums\ContentIdeaStatus;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContentIdeasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contentProject.name')
                    ->searchable(),
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('topic')
                    ->searchable(),
                TextColumn::make('source')
                    ->searchable(),
                TextColumn::make('source_url')
                    ->searchable(),
                TextColumn::make('score')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('approve')
                    ->visible(fn (ContentIdea $record): bool => $record->status === ContentIdeaStatus::New)
                    ->requiresConfirmation()
                    ->action(function (ContentIdea $record): void {
                        $record->update(['status' => ContentIdeaStatus::Approved]);

                        Notification::make()->title('Idea approved')->success()->send();
                    }),
                Action::make('reject')
                    ->visible(fn (ContentIdea $record): bool => $record->status === ContentIdeaStatus::New)
                    ->requiresConfirmation()
                    ->color('danger')
                    ->action(function (ContentIdea $record): void {
                        $record->update(['status' => ContentIdeaStatus::Rejected]);

                        Notification::make()->title('Idea rejected')->success()->send();
                    }),
                Action::make('generateScript')
                    ->label('Generate Script')
                    ->visible(fn (ContentIdea $record): bool => $record->status === ContentIdeaStatus::Approved)
                    ->requiresConfirmation()
                    ->action(function (ContentIdea $record): void {
                        GenerateScriptJob::dispatch($record->id);

                        Notification::make()->title('Script generation queued')->success()->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
```

- [ ] **Step 5: Run tests**

```bash
php artisan test --filter=ContentIdeaActionsTest
vendor/bin/pint --test
```

Expected: 5 tests pass, Pint clean.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/ContentIdeas/Pages/ListContentIdeas.php app/Filament/Resources/ContentIdeas/Tables/ContentIdeasTable.php tests/Feature/Filament/ContentIdeaActionsTest.php
git commit -m "Filament: Generate Idea, Approve, Reject, Generate Script actions on ContentIdea"
```

---

### Task 10: Filament — `ScriptResource` becomes view-only

**Files:**
- Modify: `app/Filament/Resources/Scripts/ScriptResource.php`
- Modify: `app/Filament/Resources/Scripts/Pages/ListScripts.php`
- Modify: `app/Filament/Resources/Scripts/Tables/ScriptsTable.php`
- Create: `app/Filament/Resources/Scripts/Pages/ViewScript.php`
- Delete: `app/Filament/Resources/Scripts/Pages/CreateScript.php`
- Delete: `app/Filament/Resources/Scripts/Pages/EditScript.php`
- Test: `tests/Feature/Filament/ScriptResourceViewOnlyTest.php`

**Interfaces:**
- Consumes: `App\Models\Enums\ScriptStatus` (Task 4).
- Produces: nothing downstream depends on this — it is the spec's final Filament requirement ("`ScriptResource` — view-only перегляд").

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/ScriptResourceViewOnlyTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Models\Enums\ScriptStatus;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScriptResourceViewOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_create_route_no_longer_exists(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get('/admin/scripts/create');

        $response->assertNotFound();
    }

    public function test_a_script_can_be_viewed(): void
    {
        $this->actingAs(User::factory()->create());

        $script = Script::factory()->create(['status' => ScriptStatus::Completed]);

        $this->get('/admin/scripts')->assertSuccessful();
        $this->get("/admin/scripts/{$script->id}")->assertSuccessful();
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=ScriptResourceViewOnlyTest
```

Expected: FAIL — `/admin/scripts/create` still exists (200, not 404), and `/admin/scripts/{id}` (the future view route) 404s because only `edit` exists today.

- [ ] **Step 3: Delete the create/edit pages**

```bash
rm app/Filament/Resources/Scripts/Pages/CreateScript.php
rm app/Filament/Resources/Scripts/Pages/EditScript.php
```

- [ ] **Step 4: Add the view page**

Create `app/Filament/Resources/Scripts/Pages/ViewScript.php`:

```php
<?php

namespace App\Filament\Resources\Scripts\Pages;

use App\Filament\Resources\Scripts\ScriptResource;
use Filament\Resources\Pages\ViewRecord;

class ViewScript extends ViewRecord
{
    protected static string $resource = ScriptResource::class;
}
```

(`ViewRecord` reuses `ScriptResource::form()` — i.e. the existing `ScriptForm` — but renders it fully disabled automatically; no changes needed to `ScriptForm.php`.)

- [ ] **Step 5: Update `ScriptResource::getPages()`**

Edit `app/Filament/Resources/Scripts/ScriptResource.php` — replace the `use` imports for the removed pages and the `getPages()` method:

```php
<?php

namespace App\Filament\Resources\Scripts;

use App\Filament\Resources\Scripts\Pages\ListScripts;
use App\Filament\Resources\Scripts\Pages\ViewScript;
use App\Filament\Resources\Scripts\Schemas\ScriptForm;
use App\Filament\Resources\Scripts\Tables\ScriptsTable;
use App\Models\Script;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ScriptResource extends Resource
{
    protected static ?string $model = Script::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Content Projects';

    public static function form(Schema $schema): Schema
    {
        return ScriptForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScriptsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScripts::route('/'),
            'view' => ViewScript::route('/{record}'),
        ];
    }
}
```

- [ ] **Step 6: Drop the "Create" header action and switch the row action to "View"**

Replace the whole file `app/Filament/Resources/Scripts/Pages/ListScripts.php`:

```php
<?php

namespace App\Filament\Resources\Scripts\Pages;

use App\Filament\Resources\Scripts\ScriptResource;
use Filament\Resources\Pages\ListRecords;

class ListScripts extends ListRecords
{
    protected static string $resource = ScriptResource::class;
}
```

Edit `app/Filament/Resources/Scripts/Tables/ScriptsTable.php` — replace the `EditAction` import/usage with `ViewAction`, and add `->badge()` to the `status` column:

```php
<?php

namespace App\Filament\Resources\Scripts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ScriptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contentIdea.title')
                    ->searchable(),
                TextColumn::make('provider')
                    ->searchable(),
                TextColumn::make('model')
                    ->searchable(),
                TextColumn::make('prompt_version')
                    ->searchable(),
                TextColumn::make('estimated_duration')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
```

- [ ] **Step 7: Run tests**

```bash
php artisan test --filter=ScriptResourceViewOnlyTest
php artisan test --filter=FilamentResourcesTest
vendor/bin/pint --test
```

Expected: both pass — `FilamentResourcesTest` still passes because it only hits `/admin/scripts` (index), not the removed create/edit routes.

- [ ] **Step 8: Commit**

```bash
git add app/Filament/Resources/Scripts tests/Feature/Filament/ScriptResourceViewOnlyTest.php
git commit -m "Filament: ScriptResource becomes view-only"
```

---

### Task 11: `phpunit.xml` DB_HOST/DB_PORT chore + final full verification

**Files:**
- Modify: `phpunit.xml`
- Modify: `.gitignore`
- Modify: `README.md`
- Create: `.env.testing.example`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing downstream depends on this — it is the Phase 1 review carry-over item and the final verification gate for the whole phase.

- [ ] **Step 1: Remove the hardcoded `DB_HOST`/`DB_PORT` from `phpunit.xml`**

Edit `phpunit.xml` — remove these two lines from the `<php>` block (keep `DB_CONNECTION`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_URL` as they are):

```xml
        <env name="DB_HOST" value="127.0.0.1"/>
        <env name="DB_PORT" value="5432"/>
```

- [ ] **Step 2: Add a committed example env-testing file**

Create `.env.testing.example`:

```
DB_HOST=127.0.0.1
DB_PORT=5432
```

- [ ] **Step 3: Gitignore the real `.env.testing`**

Edit `.gitignore` — add `.env.testing` alongside the existing `.env` entries:

```
.env
.env.backup
.env.production
.env.testing
```

- [ ] **Step 4: Update the README's Тести section**

Edit `README.md` — replace the "Тести" section:

```markdown
## Тести

`php artisan test` потребує доступного Postgres. Хост/порт задаються через
`.env.testing` (скопіюйте `cp .env.testing.example .env.testing` і за потреби
відредагуйте `DB_PORT`, якщо 5432 вже зайнятий на вашій машині — `phpunit.xml`
більше не хардкодить ці значення). Решта параметрів (`DB_DATABASE=autocontent_testing`,
`DB_USERNAME`/`DB_PASSWORD=autocontent`) задані в `phpunit.xml`. Перед запуском тестів
піднімайте `docker compose up -d postgres redis` (за потреби перевизначте порти через
`POSTGRES_HOST_PORT`/`REDIS_HOST_PORT`, якщо порти 5432/6379 вже зайняті).

\```bash
cp .env.testing.example .env.testing
docker compose up -d postgres redis
php artisan test
vendor/bin/pint --test
\```
```

(Use real triple-backtick fences, not escaped, when writing the file — they're escaped here only so this instruction block doesn't close early.)

- [ ] **Step 5: Create a local `.env.testing` and confirm tests still run**

```bash
cp .env.testing.example .env.testing
docker compose up -d postgres redis
php artisan test
```

Expected: every test from Tasks 1-10 still passes (this is the proof the `.env.testing` fallback actually works now that `phpunit.xml` no longer forces `DB_HOST`/`DB_PORT`).

- [ ] **Step 6: Run the full Phase 2 verification suite**

```bash
php artisan migrate:fresh --seed --database=pgsql
php artisan test
vendor/bin/pint --test
php artisan route:list > /dev/null
```

Expected: every test from Phase 1 and Phase 2 passes, Pint clean, `route:list` doesn't error, `migrate:fresh --seed` completes (Phase 1's `DatabaseSeeder` still works — Phase 2 added no migrations).

- [ ] **Step 7: Commit**

```bash
git add phpunit.xml .gitignore README.md .env.testing.example
git commit -m "Stop hardcoding DB_HOST/DB_PORT in phpunit.xml; use .env.testing"
```

---

## Phase 2 DoD checklist (TechnicalTask.md §24, items 1-4)

After Task 11, manually verify once against a real Postgres+Redis stack (not required to be scripted, but worth doing before closing the phase):

1. Create a `ContentProject` in Filament with `settings.ai.script` set to a provider/model pair different from the global default (Task 8's fields).
2. Use the "Generate Idea" header action on `ContentIdeaResource` to create a `ContentIdea` (Task 9) — with `OPENAI_API_KEY`/`ANTHROPIC_API_KEY` set in `.env`, this hits the real provider matching `settings.ai.idea` (or the global default if unset).
3. Approve the idea, then use "Generate Script" (Task 9) — confirm the queued `GenerateScriptJob` (Horizon worker running) produces a `Script` with `status = Completed` whose `provider`/`model` match `ContentProject.settings.ai.script`, not the config default.
4. View the resulting `Script` in the now-view-only `ScriptResource` (Task 10).

Update `ROADMAP.md`'s Phase 2 section (checkboxes, "завершено" date, and a "Для Phase 3" carry-over note — Phase 3 will need `GenerateScenesService` for scene breakdown, and should double check `Script.metadata['title']` isn't confused with a future dedicated column) once this is done.
