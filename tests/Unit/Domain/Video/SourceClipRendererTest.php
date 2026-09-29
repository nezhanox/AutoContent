<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\Providers\SourceClipRenderer;
use App\Models\ContentProject;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\SourceChannelFraming;
use App\Models\MediaAsset;
use App\Models\SourceChannel;
use App\Models\SourceClip;
use App\Models\SourceVideo;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class SourceClipRendererTest extends TestCase
{
    use RefreshDatabase;

    private function fakeFfmpegProcesses(bool $sourceHasAudio = true, bool $ffmpegFails = false): void
    {
        Process::fake(function ($process) use ($sourceHasAudio, $ffmpegFails) {
            $command = $process->command;

            if (in_array('-show_streams', $command, true)) {
                $isOutput = str_ends_with((string) end($command), 'output.mp4');
                $streams = [['codec_type' => 'video', 'width' => $isOutput ? 1080 : 1920, 'height' => $isOutput ? 1920 : 1080]];
                if ($sourceHasAudio || $isOutput) {
                    $streams[] = ['codec_type' => 'audio'];
                }

                return Process::result(output: json_encode([
                    'format' => ['duration' => $isOutput ? '60.00' : '600.00'],
                    'streams' => $streams,
                ]));
            }

            if ($ffmpegFails) {
                return Process::result(errorOutput: 'no such filter', exitCode: 1);
            }

            $output = end($command);
            if (is_string($output) && str_ends_with($output, '.mp4')) {
                file_put_contents($output, 'fake-video-bytes');
            }

            return Process::result(output: '');
        });
    }

    private function buildVideo(SourceChannelFraming $framing = SourceChannelFraming::BlurPad): Video
    {
        $project = ContentProject::factory()->create();
        $channel = SourceChannel::factory()->create([
            'content_project_id' => $project->id,
            'framing' => $framing,
        ]);
        $sourceVideo = SourceVideo::factory()->create([
            'source_channel_id' => $channel->id,
            'file_path' => 'source/abc.mp4',
        ]);
        $clip = SourceClip::factory()->create([
            'source_video_id' => $sourceVideo->id,
            'start' => 10.0,
            'end' => 70.0,
        ]);
        $subtitle = MediaAsset::factory()->create([
            'type' => MediaAssetType::Subtitle,
            'metadata' => ['segments' => [['start' => 0.0, 'end' => 1.0, 'text' => 'Hi']], 'language' => 'en'],
        ]);
        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'source_clip_id' => $clip->id,
            'subtitle_id' => $subtitle->id,
        ]);

        Storage::disk(config('filesystems.default'))->put('source/abc.mp4', 'fake-source-bytes');

        return $video->fresh();
    }

    private function filterOf(array $command): string
    {
        $index = array_search('-filter_complex', $command, true);

        return $index === false ? '' : (string) $command[$index + 1];
    }

    private function isRenderCommand(array $command): bool
    {
        return in_array('-filter_complex', $command, true);
    }

    public function test_it_renders_blur_pad_with_trim_loudnorm_and_burned_subtitles(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeFfmpegProcesses();

        (new SourceClipRenderer)->render($this->buildVideo());

        Process::assertRan(function ($process) {
            $command = $process->command;
            if (! $this->isRenderCommand($command)) {
                return false;
            }
            $filter = $this->filterOf($command);
            $ss = array_search('-ss', $command, true);
            $t = array_search('-t', $command, true);

            return $ss !== false && $command[$ss + 1] === '10'
                && $t !== false && $command[$t + 1] === '60'
                && str_contains($filter, 'boxblur')
                && str_contains($filter, 'overlay')
                && str_contains($filter, 'loudnorm=I=-16:TP=-1.5:LRA=11,aresample=48000[a]')
                && str_contains($filter, 'ass=')
                && in_array('[a]', $command, true);
        });
    }

    public function test_it_burns_lower_third_subtitles_with_the_bundled_clip_font(): void
    {
        Storage::fake(config('filesystems.default'));
        $captured = [];
        Process::fake(function ($process) use (&$captured) {
            $command = $process->command;
            if (in_array('-show_streams', $command, true)) {
                return Process::result(output: json_encode([
                    'format' => ['duration' => '60.00'],
                    'streams' => [['codec_type' => 'video', 'width' => 1080, 'height' => 1920], ['codec_type' => 'audio']],
                ]));
            }
            if (in_array('-filter_complex', $command, true)) {
                $filter = $command[array_search('-filter_complex', $command, true) + 1];
                preg_match('/ass=(.+?)\\.ass:fontsdir=/', $filter, $m);
                $captured['filter'] = $filter;
                $captured['ass'] = file_get_contents(str_replace('\\\\', '\\', $m[1]).'.ass');
                file_put_contents(end($command), 'fake-video-bytes');
            }

            return Process::result(output: '');
        });

        (new SourceClipRenderer)->render($this->buildVideo());

        $style = config('clips.subtitles');
        $this->assertStringContainsString("Style: Default,{$style['font']},{$style['font_size']},", $captured['ass']);
        $this->assertMatchesRegularExpression('/,0,1,\d+,\d+,2,\d+,\d+,'.$style['margin_v'].'\n/', $captured['ass']);
        $this->assertStringContainsString(':fontsdir=', $captured['filter']);
        $this->assertFileExists(config('clips.fonts_dir').'/Poppins-ExtraBold.ttf');
        $this->assertFileExists(config('clips.fonts_dir').'/OFL.txt');
    }

    public function test_it_renders_crop_framing_without_blur(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeFfmpegProcesses();

        (new SourceClipRenderer)->render($this->buildVideo(SourceChannelFraming::Crop));

        Process::assertRan(function ($process) {
            $filter = $this->filterOf($process->command);

            return $filter !== ''
                && str_contains($filter, 'crop=')
                && str_contains($filter, 'ass=')
                && ! str_contains($filter, 'boxblur');
        });
    }

    public function test_it_adds_silence_when_the_source_has_no_audio(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeFfmpegProcesses(sourceHasAudio: false);

        (new SourceClipRenderer)->render($this->buildVideo());

        Process::assertRan(function ($process) {
            $command = $process->command;
            if (! $this->isRenderCommand($command)) {
                return false;
            }
            $map = array_keys($command, '-map', true);
            $mapped = array_map(fn ($i) => $command[$i + 1], $map);

            return str_contains(implode(' ', $command), 'anullsrc')
                && in_array('1:a', $mapped, true)
                && ! str_contains($this->filterOf($command), 'loudnorm');
        });
    }

    public function test_it_returns_probed_result_and_stores_the_file(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeFfmpegProcesses();

        $video = $this->buildVideo();
        $result = (new SourceClipRenderer)->render($video);

        $this->assertSame("projects/{$video->content_project_id}/renders/{$video->id}.mp4", $result->path);
        $this->assertSame(60.0, $result->duration);
        $this->assertSame(1080, $result->width);
        $this->assertSame(1920, $result->height);
        $this->assertSame('blur_pad', $result->metadata['framing']);
        $this->assertSame($video->source_clip_id, $result->metadata['source_clip_id']);
        Storage::disk(config('filesystems.default'))->assertExists($result->path);
        $this->assertSame('fake-video-bytes', Storage::disk(config('filesystems.default'))->get($result->path));
    }

    public function test_it_throws_when_the_video_has_no_source_clip(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeFfmpegProcesses();

        $video = Video::factory()->create(['source_clip_id' => null]);

        $this->expectException(RuntimeException::class);

        (new SourceClipRenderer)->render($video);
    }

    public function test_it_throws_when_ffmpeg_fails(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeFfmpegProcesses(ffmpegFails: true);

        $video = $this->buildVideo();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Clip render failed');

        (new SourceClipRenderer)->render($video);
    }
}
