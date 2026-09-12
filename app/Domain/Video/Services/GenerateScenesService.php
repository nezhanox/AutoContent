<?php

namespace App\Domain\Video\Services;

use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Llm\ResolvedLlmTarget;
use App\Domain\Video\Exceptions\SceneGenerationFailedException;
use App\Models\Enums\VideoSceneType;
use App\Models\Script;
use InvalidArgumentException;
use JsonException;

final class GenerateScenesService
{
    private const MAX_REPAIR_ATTEMPTS = 2;

    public function __construct(private readonly LlmManagerInterface $llmManager) {}

    /**
     * @return array<int, array{type: string, duration: int, visual_query: ?string, text: string}>
     */
    public function generate(Script $script, ResolvedLlmTarget $target): array
    {
        $project = $script->contentIdea->contentProject;
        $messages = $this->buildMessages($script);
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

        throw new SceneGenerationFailedException($lastError);
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(Script $script): array
    {
        $system = 'You are a video editor breaking a script into an ordered list of scenes for a short '
            .'vertical video. Each scene has a type, an approximate duration in seconds, an optional '
            .'visual search query describing what should be shown on screen, and the portion of narration '
            .'text spoken during it. Respond only with JSON matching the given schema — no prose outside the JSON.';

        $user = sprintf(
            "Break this script into scenes.\nTitle: %s\nScript:\n%s",
            $script->metadata['title'] ?? '',
            $script->content,
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
            'name' => 'video_scenes',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'scenes' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => [
                                    'type' => 'string',
                                    'enum' => array_map(fn (VideoSceneType $case) => $case->value, VideoSceneType::cases()),
                                ],
                                'duration' => ['type' => 'integer'],
                                'visual_query' => ['type' => ['string', 'null']],
                                'text' => ['type' => 'string'],
                            ],
                            'required' => ['type', 'duration', 'visual_query', 'text'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['scenes'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ];
    }

    /**
     * @return array<int, array{type: string, duration: int, visual_query: ?string, text: string}>
     */
    private function parse(string $content): array
    {
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! array_key_exists('scenes', $data) || ! is_array($data['scenes'])) {
            throw new InvalidArgumentException('Response is missing a [scenes] array.');
        }

        if ($data['scenes'] === []) {
            throw new InvalidArgumentException('Field [scenes] must not be empty.');
        }

        $validTypes = array_map(fn (VideoSceneType $case) => $case->value, VideoSceneType::cases());
        $scenes = [];

        foreach ($data['scenes'] as $index => $scene) {
            if (! is_array($scene)) {
                throw new InvalidArgumentException("Scene [{$index}] is not a JSON object.");
            }

            foreach (['type', 'duration', 'visual_query', 'text'] as $field) {
                if (! array_key_exists($field, $scene)) {
                    throw new InvalidArgumentException("Scene [{$index}] is missing required field [{$field}].");
                }
            }

            if (! is_string($scene['type']) || ! in_array($scene['type'], $validTypes, true)) {
                throw new InvalidArgumentException("Scene [{$index}] field [type] must be one of: ".implode(', ', $validTypes).'.');
            }

            if (! is_int($scene['duration'])) {
                throw new InvalidArgumentException("Scene [{$index}] field [duration] must be an integer.");
            }

            if ($scene['duration'] < 1) {
                throw new InvalidArgumentException("Scene [{$index}] field [duration] must be a positive integer.");
            }

            if ($scene['visual_query'] !== null && ! is_string($scene['visual_query'])) {
                throw new InvalidArgumentException("Scene [{$index}] field [visual_query] must be a string or null.");
            }

            if (is_string($scene['visual_query']) && strlen($scene['visual_query']) > 255) {
                throw new InvalidArgumentException("Scene [{$index}] field [visual_query] must be 255 characters or fewer.");
            }

            if (! is_string($scene['text'])) {
                throw new InvalidArgumentException("Scene [{$index}] field [text] must be a string.");
            }

            $scenes[] = [
                'type' => $scene['type'],
                'duration' => $scene['duration'],
                'visual_query' => $scene['visual_query'],
                'text' => $scene['text'],
            ];
        }

        return $scenes;
    }
}
