<?php

namespace App\Domain\Publishing\Services;

use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Llm\ResolvedLlmTarget;
use App\Domain\Publishing\Exceptions\CaptionGenerationFailedException;
use App\Models\Publication;
use InvalidArgumentException;
use JsonException;

final class GenerateCaptionsService
{
    private const MAX_REPAIR_ATTEMPTS = 2;

    public function __construct(private readonly LlmManagerInterface $llmManager) {}

    /**
     * @return array{caption: string, hashtags: array<int, string>}
     */
    public function generate(Publication $publication, ResolvedLlmTarget $target): array
    {
        $project = $publication->video->contentProject;
        $messages = $this->buildMessages($publication);
        $lastError = 'unknown validation error';

        for ($attempt = 0; $attempt <= self::MAX_REPAIR_ATTEMPTS; $attempt++) {
            $response = $this->llmManager->complete(
                project: $project,
                purpose: 'captions',
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

        throw new CaptionGenerationFailedException($lastError);
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(Publication $publication): array
    {
        $video = $publication->video;
        $platform = $publication->socialAccount->platform->value;

        $system = sprintf(
            'You are a social media copywriter. Write a caption and hashtags for a short vertical video '.
            'being posted to %s. Respond only with JSON matching the given schema — no prose outside the JSON.',
            $platform,
        );

        $user = sprintf(
            "Video title: %s\nVideo description: %s",
            $video->title,
            $video->description,
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
            'name' => 'publication_caption',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'caption' => ['type' => 'string'],
                    'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['caption', 'hashtags'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ];
    }

    /**
     * @return array{caption: string, hashtags: array<int, string>}
     */
    private function parse(string $content): array
    {
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new InvalidArgumentException('Response is not a JSON object.');
        }

        foreach (['caption', 'hashtags'] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidArgumentException("Missing required field [{$field}].");
            }
        }

        if (! is_string($data['caption'])) {
            throw new InvalidArgumentException('Field [caption] must be a string.');
        }

        if (! is_array($data['hashtags'])) {
            throw new InvalidArgumentException('Field [hashtags] must be an array.');
        }

        foreach ($data['hashtags'] as $tag) {
            if (! is_string($tag)) {
                throw new InvalidArgumentException('Field [hashtags] must contain only strings.');
            }
        }

        return [
            'caption' => $data['caption'],
            'hashtags' => array_values($data['hashtags']),
        ];
    }
}
