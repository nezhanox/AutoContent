<?php

namespace App\Domain\Source\Services;

use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Source\Exceptions\ClipSelectionFailedException;
use App\Domain\Source\Exceptions\InvalidClipSelectionException;
use App\Domain\Source\Support\Clip;
use App\Domain\Source\Support\ClipConstraints;
use App\Domain\Source\Support\ClipMerger;
use App\Domain\Source\Support\ClipValidator;
use App\Domain\Source\Support\TranscriptPromptFormatter;
use App\Domain\Source\Support\TranscriptWindower;
use App\Domain\Source\Support\Utterance;
use App\Models\Enums\SourceChannelMode;
use App\Models\SourceChannel;
use App\Models\SourceVideo;
use InvalidArgumentException;
use JsonException;

final class ClipSelector
{
    private const MAX_REPAIR_ATTEMPTS = 2;

    public function __construct(
        private readonly LlmManagerInterface $llmManager,
        private readonly TranscriptPromptFormatter $formatter,
        private readonly ClipValidator $validator,
        private readonly ClipMerger $merger,
        private readonly TranscriptWindower $windower,
    ) {}

    /**
     * @param  list<Utterance>  $utterances
     * @return list<Clip>
     *
     * @throws ClipSelectionFailedException
     */
    public function select(SourceChannel $channel, SourceVideo $video, array $utterances): array
    {
        $constraints = ClipConstraints::fromChannel($channel);

        if ($constraints->mode === SourceChannelMode::Whole) {
            return [new Clip(0.0, (float) $video->duration, $video->title)];
        }

        if ($utterances === []) {
            return [];
        }

        $windows = $this->windower->windows(
            $utterances,
            config('clips.window_minutes') * 60,
            config('clips.window_overlap_seconds'),
        );

        $clips = [];
        $lastIndex = count($windows) - 1;
        foreach ($windows as $index => $window) {
            $clips = array_merge($clips, $this->selectWindow($channel, $window, $constraints, $index === $lastIndex));
        }

        return $this->merger->merge($clips, $constraints);
    }

    /**
     * @param  list<Utterance>  $window
     * @return list<Clip>
     */
    private function selectWindow(SourceChannel $channel, array $window, ClipConstraints $constraints, bool $finalWindow): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($constraints)],
            ['role' => 'user', 'content' => $this->formatter->format($window)],
        ];
        $lastError = 'unknown validation error';

        for ($attempt = 0; $attempt <= self::MAX_REPAIR_ATTEMPTS; $attempt++) {
            $response = $this->llmManager->complete(
                project: $channel->contentProject,
                purpose: 'clip_selection',
                messages: $messages,
                responseSchema: $this->schema(),
                temperature: 0.3,
                maxTokens: (int) config('clips.max_output_tokens', 8192),
            );

            try {
                if (in_array($response->metadata['finish_reason'] ?? null, ['length', 'max_tokens'], true)) {
                    throw new InvalidClipSelectionException('Response was truncated (output token limit). Return fewer clips.');
                }

                $data = json_decode($response->content, true, flags: JSON_THROW_ON_ERROR);

                if (! is_array($data) || ! isset($data['clips']) || ! is_array($data['clips'])) {
                    throw new InvalidArgumentException('Response is missing a [clips] array.');
                }

                return $this->validator->validate($data['clips'], $window, $constraints, $finalWindow);
            } catch (JsonException|InvalidArgumentException $exception) {
                $lastError = $exception->getMessage();
                $messages[] = ['role' => 'assistant', 'content' => $response->content];
                $messages[] = [
                    'role' => 'user',
                    'content' => "Invalid response: {$lastError}. Reply again with valid JSON matching the schema exactly.",
                ];
            }
        }

        throw new ClipSelectionFailedException($lastError);
    }

    private function systemPrompt(ClipConstraints $constraints): string
    {
        $intro = 'You are a video editor. The transcript is a list of numbered utterances: '
            ."`[id] mm:ss\u{2013}mm:ss (Ns) text`. ";

        if ($constraints->mode === SourceChannelMode::Fixed) {
            return $intro.sprintf(
                'Split the video into consecutive clips of about %ds each (allowed %d'."\u{2013}".'%ds: the summed durations of utterances start_id..end_id). '
                .'Cut only between utterances, at natural topic boundaries; never split a sentence. '
                .'Clips must not overlap and should cover the transcript in order. '
                .'For each clip give a short title, a one-sentence hook, an engagement score 1'."\u{2013}".'10 and a brief reason. '
                ."Write title, hook and reason in the transcript's language. Respond only with JSON matching the schema.",
                $constraints->targetSeconds,
                $constraints->minSeconds(),
                $constraints->maxSeconds(),
            );
        }

        return $intro.sprintf(
            'Pick only the genuinely most interesting self-contained moments (a complete thought understandable without the rest of the video: '
            .'a strong claim, story, insight or emotional peak). '
            .'Return at most %d clips '."\u{2013}".' fewer, or none, if fewer are truly good; never pad with weak ones. '
            .'Each clip lasts %d'."\u{2013}".'%ds (the summed durations of utterances start_id..end_id), starts where a thought begins '
            .'and ends at a natural conclusion, and clips must not overlap. '
            .'Score 1'."\u{2013}".'10 how engaging each is for a short-form video audience and give a short title, a one-sentence hook and a brief reason. '
            ."Write title, hook and reason in the transcript's language. Respond only with JSON matching the schema.",
            $constraints->maxClips,
            $constraints->minSeconds(),
            $constraints->maxSeconds(),
        );
    }

    /**
     * @return array{name: string, schema: array<string, mixed>, strict: bool}
     */
    private function schema(): array
    {
        return [
            'name' => 'clip_selection',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'clips' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'start_id' => ['type' => 'integer'],
                                'end_id' => ['type' => 'integer'],
                                'title' => ['type' => 'string'],
                                'hook' => ['type' => 'string'],
                                'score' => ['type' => 'integer'],
                                'reason' => ['type' => 'string'],
                            ],
                            'required' => ['start_id', 'end_id', 'title', 'hook', 'score', 'reason'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['clips'],
                'additionalProperties' => false,
            ],
            'strict' => true,
        ];
    }
}
