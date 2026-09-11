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
        $startedAt = microtime(true);
        $target = null;

        try {
            $target = $this->resolve($project, $purpose, $providerOverride, $modelOverride);

            $request = new LlmRequest(
                purpose: $purpose,
                messages: $messages,
                responseSchema: $responseSchema,
                model: $target->model,
                temperature: $temperature,
                maxTokens: $maxTokens,
            );

            $response = $target->provider->complete($request);
        } catch (Throwable $exception) {
            $this->logFailure(
                $project,
                $purpose,
                $target?->providerName ?? $providerOverride,
                $target?->model ?? $modelOverride,
                $startedAt,
                $exception,
            );

            throw $exception;
        }

        $this->log($project, $purpose, $target, $response, $startedAt);

        return $response;
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
            'status' => LlmUsageLogStatus::Success,
            'metadata' => [],
        ]);
    }

    private function logFailure(
        ?ContentProject $project,
        string $purpose,
        ?string $providerName,
        ?string $model,
        float $startedAt,
        Throwable $exception,
    ): void {
        LlmUsageLog::create([
            'content_project_id' => $project?->id,
            'purpose' => $purpose,
            'provider' => $providerName ?? 'unknown',
            'model' => $model ?? 'unknown',
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

        if ($pricing === null || ! isset($pricing['input_cost_per_1k'], $pricing['output_cost_per_1k'])) {
            return null;
        }

        return round(
            ($response->promptTokens / 1000) * $pricing['input_cost_per_1k']
            + ($response->completionTokens / 1000) * $pricing['output_cost_per_1k'],
            6
        );
    }
}
