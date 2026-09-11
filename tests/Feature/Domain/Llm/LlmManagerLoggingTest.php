<?php

namespace Tests\Feature\Domain\Llm;

use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Llm\LlmRequest;
use App\Domain\Llm\LlmResponse;
use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Models\ContentProject;
use App\Models\Enums\LlmUsageLogStatus;
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
            $this->assertDatabaseHas('llm_usage_logs', [
                'purpose' => 'idea',
                'provider' => 'fake',
                'status' => LlmUsageLogStatus::Failed->value,
                'error_message' => 'provider unavailable',
            ]);
        }
    }
}
