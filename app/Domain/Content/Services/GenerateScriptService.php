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
