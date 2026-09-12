<?php

namespace Tests\Feature\Domain\Llm;

use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\LlmResponse;
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
            return (new FakeLlmProvider)->respondWith('{"ok":true}', 100, 50);
        });

        // ContentProjectFactory seeds settings.ai.default (provider "openai"), which would
        // outrank the global "fake" default set above per LlmManager's resolution priority
        // and route to the not-yet-implemented OpenAiLlmProvider. Override settings here so
        // this test exercises the global-default fallback it's actually meant to cover.
        $project = ContentProject::factory()->create(['settings' => []]);

        $manager = $this->app->make(LlmManagerInterface::class);
        $response = $manager->complete($project, 'script', [['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('{"ok":true}', $response->content);

        $this->assertDatabaseCount('llm_usage_logs', 1);

        $log = LlmUsageLog::sole();

        $this->assertSame($project->id, $log->content_project_id);
        $this->assertSame('script', $log->purpose);
        $this->assertSame('fake', $log->provider);
        $this->assertSame(100, $log->prompt_tokens);
        $this->assertSame(50, $log->completion_tokens);
        $this->assertSame(LlmUsageLogStatus::Success, $log->status);
        $this->assertSame([], $log->metadata);
    }

    public function test_cost_is_estimated_for_a_model_whose_name_contains_a_dot(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model-4.5');

        // The provider echoes back a dated snapshot id rather than the requested
        // alias — pricing must still be resolved from the alias that was asked for.
        $this->app->bind(FakeLlmProvider::class, function () {
            return new class extends FakeLlmProvider
            {
                public function complete(LlmRequest $request): LlmResponse
                {
                    return new LlmResponse(
                        content: '{"ok":true}',
                        provider: 'fake',
                        model: $request->model.'-20260101',
                        promptTokens: 100,
                        completionTokens: 50,
                    );
                }
            };
        });

        $manager = $this->app->make(LlmManagerInterface::class);
        $manager->complete(null, 'script', [['role' => 'user', 'content' => 'hi']]);

        $log = LlmUsageLog::sole();

        // (100/1000 * 0.001) + (50/1000 * 0.005) = 0.00035
        $this->assertNotNull($log->cost, 'Cost was not estimated for a dotted model name.');
        $this->assertEqualsWithDelta(0.00035, (float) $log->cost, 0.0000001);
    }

    public function test_complete_logs_a_failed_call_and_rethrows(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return new class extends FakeLlmProvider
            {
                public function complete(LlmRequest $request): LlmResponse
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
            $this->assertDatabaseCount('llm_usage_logs', 1);

            $log = LlmUsageLog::sole();

            $this->assertSame('idea', $log->purpose);
            $this->assertSame('fake', $log->provider);
            $this->assertSame(LlmUsageLogStatus::Failed, $log->status);
            $this->assertSame('provider unavailable', $log->error_message);
            $this->assertSame([], $log->metadata);
        }
    }
}
