<?php

namespace Tests\Feature\Domain\Source;

use App\Domain\Source\Exceptions\ClipSelectionFailedException;
use App\Domain\Source\Services\ClipSelector;
use App\Domain\Source\Support\ClipMerger;
use App\Domain\Source\Support\ClipValidator;
use App\Domain\Source\Support\TranscriptPromptFormatter;
use App\Domain\Source\Support\TranscriptWindower;
use App\Domain\Source\Support\Utterance;
use App\Models\Enums\SourceChannelMode;
use App\Models\SourceChannel;
use App\Models\SourceVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QueuedLlmManager;
use Tests\TestCase;

class ClipSelectorTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<Utterance> 10-second utterances with ids 1..$count */
    private function utterances(int $count): array
    {
        $list = [];
        for ($i = 1; $i <= $count; $i++) {
            $list[] = new Utterance($i, ($i - 1) * 10.0, $i * 10.0, "Sentence number {$i}.");
        }

        return $list;
    }

    private function channel(SourceChannelMode $mode, int $target = 60, int $tolerance = 15): SourceChannel
    {
        return SourceChannel::factory()->create([
            'mode' => $mode,
            'target_seconds' => $target,
            'tolerance_seconds' => $tolerance,
            'max_clips' => 3,
            'min_score' => 6,
        ]);
    }

    private function selector(QueuedLlmManager $llm): ClipSelector
    {
        return new ClipSelector($llm, new TranscriptPromptFormatter, new ClipValidator, new ClipMerger, new TranscriptWindower);
    }

    private function clipJson(int $start, int $end, int $score = 8): array
    {
        return ['start_id' => $start, 'end_id' => $end, 'title' => "T{$start}", 'hook' => 'H', 'score' => $score, 'reason' => 'R'];
    }

    public function test_whole_mode_returns_full_video_without_calling_llm(): void
    {
        $channel = $this->channel(SourceChannelMode::Whole);
        $video = SourceVideo::factory()->create(['source_channel_id' => $channel->id, 'duration' => 321, 'title' => 'My video']);
        $llm = new QueuedLlmManager([]);

        $clips = $this->selector($llm)->select($channel, $video, $this->utterances(5));

        $this->assertCount(1, $clips);
        $this->assertSame(0.0, $clips[0]->start);
        $this->assertSame(321.0, $clips[0]->end);
        $this->assertSame('My video', $clips[0]->title);
        $this->assertSame([], $llm->purposes);
    }

    public function test_highlights_returns_validated_clips(): void
    {
        $channel = $this->channel(SourceChannelMode::Highlights);
        $video = SourceVideo::factory()->create(['source_channel_id' => $channel->id]);
        $llm = new QueuedLlmManager([json_encode(['clips' => [$this->clipJson(3, 8)]])]);

        $clips = $this->selector($llm)->select($channel, $video, $this->utterances(12));

        $this->assertCount(1, $clips);
        $this->assertEqualsWithDelta(20.0, $clips[0]->start, 0.001);
        $this->assertEqualsWithDelta(80.0, $clips[0]->end, 0.001);
        $this->assertSame('T3', $clips[0]->title);
        $this->assertSame(8, $clips[0]->score);
        $this->assertSame(['clip_selection'], $llm->purposes);
        $this->assertStringContainsString('[3] ', $llm->captured[0][1]['content']);
    }

    public function test_it_repairs_after_an_unknown_id(): void
    {
        $channel = $this->channel(SourceChannelMode::Highlights);
        $video = SourceVideo::factory()->create(['source_channel_id' => $channel->id]);
        $llm = new QueuedLlmManager([
            json_encode(['clips' => [$this->clipJson(3, 99)]]),
            json_encode(['clips' => [$this->clipJson(3, 8)]]),
        ]);

        $clips = $this->selector($llm)->select($channel, $video, $this->utterances(12));

        $this->assertCount(1, $clips);
        $this->assertCount(2, $llm->captured);
        $this->assertStringContainsString('Invalid response:', $llm->captured[1][3]['content']);
    }

    public function test_it_fits_a_slightly_short_clip_without_a_repair_call(): void
    {
        $channel = $this->channel(SourceChannelMode::Highlights, 60, 15);
        $video = SourceVideo::factory()->create(['source_channel_id' => $channel->id]);
        $utterances = [];
        $cursor = 0.0;
        foreach ([10.0, 10.0, 10.0, 11.9, 10.0, 10.0, 10.0, 10.0] as $i => $len) {
            $utterances[] = new Utterance($i + 1, $cursor, $cursor + $len, 'Sentence '.($i + 1));
            $cursor += $len;
        }
        // ids 1..4 last 41.9s, below the 45s minimum: the validator must extend, not fail.
        $llm = new QueuedLlmManager([json_encode(['clips' => [$this->clipJson(1, 4)]])]);

        $clips = $this->selector($llm)->select($channel, $video, $utterances);

        $this->assertCount(1, $llm->captured);
        $this->assertCount(1, $clips);
        $this->assertEqualsWithDelta(61.9, $clips[0]->end, 0.001);
    }

    public function test_it_extends_a_46_second_clip_toward_the_target_without_a_repair_call(): void
    {
        $channel = $this->channel(SourceChannelMode::Highlights, 60, 15);
        $video = SourceVideo::factory()->create(['source_channel_id' => $channel->id]);
        $utterances = [];
        $cursor = 0.0;
        foreach ([23.0, 23.0, 10.0, 10.0, 10.0, 10.0] as $i => $len) {
            $utterances[] = new Utterance($i + 1, $cursor, $cursor + $len, 'Sentence '.($i + 1));
            $cursor += $len;
        }
        $llm = new QueuedLlmManager([json_encode(['clips' => [$this->clipJson(1, 2)]])]);

        $clips = $this->selector($llm)->select($channel, $video, $utterances);

        $this->assertCount(1, $llm->captured);
        $this->assertCount(1, $clips);
        $this->assertGreaterThanOrEqual(60.0, $clips[0]->end - $clips[0]->start);
        $this->assertLessThanOrEqual(75.5, $clips[0]->end - $clips[0]->start);
    }

    public function test_it_throws_after_three_invalid_responses(): void
    {
        $channel = $this->channel(SourceChannelMode::Highlights);
        $video = SourceVideo::factory()->create(['source_channel_id' => $channel->id]);
        $llm = new QueuedLlmManager(['nope', 'still nope', '{"clips": "x"}']);

        $this->expectException(ClipSelectionFailedException::class);

        $this->selector($llm)->select($channel, $video, $this->utterances(12));
    }

    public function test_empty_utterances_return_empty_without_llm(): void
    {
        $channel = $this->channel(SourceChannelMode::Highlights);
        $video = SourceVideo::factory()->create(['source_channel_id' => $channel->id]);
        $llm = new QueuedLlmManager([]);

        $this->assertSame([], $this->selector($llm)->select($channel, $video, []));
        $this->assertSame([], $llm->purposes);
    }

    public function test_long_video_is_processed_in_windows_without_overlapping_clips(): void
    {
        config(['clips.window_minutes' => 1, 'clips.window_overlap_seconds' => 10]);
        $channel = $this->channel(SourceChannelMode::Highlights, 30, 15);
        $video = SourceVideo::factory()->create(['source_channel_id' => $channel->id]);
        $llm = new QueuedLlmManager([
            json_encode(['clips' => [$this->clipJson(1, 4)]]),
            json_encode(['clips' => [$this->clipJson(6, 9)]]),
            json_encode(['clips' => [$this->clipJson(11, 14)]]),
            json_encode(['clips' => [$this->clipJson(16, 18)]]),
        ]);

        $clips = $this->selector($llm)->select($channel, $video, $this->utterances(18));

        $this->assertGreaterThan(1, count($llm->purposes));
        $this->assertNotEmpty($clips);
        for ($i = 1; $i < count($clips); $i++) {
            $this->assertLessThanOrEqual($clips[$i]->start, $clips[$i - 1]->end);
        }
    }

    public function test_fixed_system_prompt_mentions_target_and_consecutive(): void
    {
        $channel = $this->channel(SourceChannelMode::Fixed, 60, 15);
        $video = SourceVideo::factory()->create(['source_channel_id' => $channel->id]);
        $llm = new QueuedLlmManager([json_encode(['clips' => [$this->clipJson(1, 6), $this->clipJson(7, 12)]])]);

        $clips = $this->selector($llm)->select($channel, $video, $this->utterances(12));

        $this->assertCount(2, $clips);
        $system = $llm->captured[0][0]['content'];
        $this->assertSame('system', $llm->captured[0][0]['role']);
        $this->assertStringContainsString('60', $system);
        $this->assertStringContainsString('consecutive', $system);
    }

    public function test_it_passes_the_configured_output_token_budget_to_the_llm(): void
    {
        config(['clips.max_output_tokens' => 6000]);
        $channel = $this->channel(SourceChannelMode::Highlights);
        $video = SourceVideo::factory()->create(['source_channel_id' => $channel->id]);
        $llm = new QueuedLlmManager([json_encode(['clips' => [$this->clipJson(3, 8)]])]);

        $this->selector($llm)->select($channel, $video, $this->utterances(12));

        $this->assertSame([6000], $llm->maxTokens);
    }

    public function test_it_asks_for_fewer_clips_when_the_response_was_truncated(): void
    {
        $channel = $this->channel(SourceChannelMode::Highlights);
        $video = SourceVideo::factory()->create(['source_channel_id' => $channel->id]);
        $llm = new QueuedLlmManager(
            ['{"clips": [{"start_id": 3', json_encode(['clips' => [$this->clipJson(3, 8)]])],
            [['finish_reason' => 'length'], []],
        );

        $clips = $this->selector($llm)->select($channel, $video, $this->utterances(12));

        $this->assertCount(1, $clips);
        $this->assertCount(2, $llm->captured);
        $this->assertStringContainsString('truncated', end($llm->captured[1])['content']);
    }

    private function capturedPrompts(SourceChannelMode $mode, ?array $transcript): array
    {
        $channel = $this->channel($mode, 60, 15);
        $video = SourceVideo::factory()->create(['source_channel_id' => $channel->id, 'transcript' => $transcript]);
        $clips = $mode === SourceChannelMode::Fixed ? [$this->clipJson(1, 6), $this->clipJson(7, 12)] : [$this->clipJson(3, 8)];
        $llm = new QueuedLlmManager([json_encode(['clips' => $clips])]);

        $this->selector($llm)->select($channel, $video, $this->utterances(12));

        return [$llm->captured[0][0]['content'], $llm->captured[0][1]['content']];
    }

    public function test_prompt_states_english_language_explicitly(): void
    {
        [$system, $user] = $this->capturedPrompts(SourceChannelMode::Highlights, ['language' => 'en']);

        $this->assertStringContainsString('in English (the transcript language). Do not use any other language.', $system);
        $this->assertStringEndsWith('title/hook/reason must be in English.', $user);
    }

    public function test_prompt_maps_ukrainian_code_in_fixed_mode(): void
    {
        [$system, $user] = $this->capturedPrompts(SourceChannelMode::Fixed, ['language' => 'uk']);

        $this->assertStringContainsString('Ukrainian', $system);
        $this->assertStringContainsString('Ukrainian', $user);
    }

    public function test_prompt_falls_back_to_unknown_language_code(): void
    {
        [$system, $user] = $this->capturedPrompts(SourceChannelMode::Highlights, ['language' => 'xx']);

        $this->assertStringContainsString('xx', $system);
        $this->assertStringContainsString('xx', $user);
    }

    public function test_prompt_without_language_has_no_explicit_language_sentence(): void
    {
        [$system, $user] = $this->capturedPrompts(SourceChannelMode::Highlights, null);

        $this->assertStringContainsString("in the transcript's language", $system);
        $this->assertStringNotContainsString('Do not use any other language', $system);
        $this->assertStringNotContainsString('must be in', $user);
    }

    public function test_both_modes_state_the_target_length(): void
    {
        foreach ([SourceChannelMode::Highlights, SourceChannelMode::Fixed] as $mode) {
            [$system] = $this->capturedPrompts($mode, ['language' => 'en']);

            $this->assertStringContainsString('Aim for about 60 seconds per clip (acceptable 45', $system);
            $this->assertStringContainsString('close to 60 seconds', $system);
        }
    }
}
