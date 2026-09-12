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
