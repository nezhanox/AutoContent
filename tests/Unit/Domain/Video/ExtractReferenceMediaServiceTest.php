<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\Providers\FakeTranscriptionProvider;
use App\Domain\Video\Services\ExtractReferenceMediaService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class ExtractReferenceMediaServiceTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir().'/reference_media_test_'.uniqid();
        File::makeDirectory($this->workDir, recursive: true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workDir);
        parent::tearDown();
    }

    private function fakeProcesses(bool $withAudio = true, float $duration = 10.0): void
    {
        Process::fake(function ($process) use ($withAudio, $duration) {
            $command = $process->command;

            if (in_array('-show_streams', $command, true)) {
                $streams = [['codec_type' => 'video', 'width' => 1080, 'height' => 1920]];

                if ($withAudio) {
                    $streams[] = ['codec_type' => 'audio'];
                }

                return Process::result(output: json_encode([
                    'format' => ['duration' => (string) $duration],
                    'streams' => $streams,
                ]));
            }

            $output = end($command);

            if (is_string($output) && (str_ends_with($output, '.wav') || str_ends_with($output, '.jpg'))) {
                file_put_contents($output, 'fake-bytes');
            }

            return Process::result(output: '');
        });
    }

    public function test_it_extracts_evenly_spaced_frames_and_transcribes_audio(): void
    {
        $this->fakeProcesses(withAudio: true, duration: 10.0);

        $provider = (new FakeTranscriptionProvider)->respondWith(
            [['start' => 0.0, 'end' => 1.0, 'text' => 'Hi']],
            'en'
        );

        $service = new ExtractReferenceMediaService($provider);
        $result = $service->analyze('/fake/source.mp4', frameCount: 4, language: 'en', workDir: $this->workDir);

        $this->assertSame(10.0, $result['duration']);
        $this->assertSame(1080, $result['width']);
        $this->assertSame(1920, $result['height']);
        $this->assertSame(
            [
                $this->workDir.'/frame-01.jpg',
                $this->workDir.'/frame-02.jpg',
                $this->workDir.'/frame-03.jpg',
                $this->workDir.'/frame-04.jpg',
            ],
            $result['frames']
        );
        $this->assertSame(
            ['language' => 'en', 'segments' => [['start' => 0.0, 'end' => 1.0, 'text' => 'Hi']]],
            $result['transcript']
        );
        $this->assertSame([], $result['notes']);
        $this->assertSame('en', $provider->lastLanguageArgument);
    }

    public function test_it_samples_frame_timestamps_at_the_midpoint_of_each_slice(): void
    {
        $timestamps = [];

        Process::fake(function ($process) use (&$timestamps) {
            $command = $process->command;

            if (in_array('-show_streams', $command, true)) {
                return Process::result(output: json_encode([
                    'format' => ['duration' => '8.0'],
                    'streams' => [['codec_type' => 'video', 'width' => 1080, 'height' => 1920]],
                ]));
            }

            $ssIndex = array_search('-ss', $command, true);

            if ($ssIndex !== false) {
                $timestamps[] = (float) $command[$ssIndex + 1];
            }

            $output = end($command);

            if (is_string($output) && str_ends_with($output, '.jpg')) {
                file_put_contents($output, 'fake-bytes');
            }

            return Process::result(output: '');
        });

        $service = new ExtractReferenceMediaService(new FakeTranscriptionProvider);
        $service->analyze('/fake/source.mp4', frameCount: 4, language: null, workDir: $this->workDir);

        $this->assertSame([1.0, 3.0, 5.0, 7.0], $timestamps);
    }

    public function test_it_skips_transcription_and_adds_a_note_when_there_is_no_audio_stream(): void
    {
        $this->fakeProcesses(withAudio: false, duration: 5.0);

        $provider = new FakeTranscriptionProvider;
        $service = new ExtractReferenceMediaService($provider);
        $result = $service->analyze('/fake/source.mp4', frameCount: 2, language: null, workDir: $this->workDir);

        $this->assertNull($result['transcript']);
        $this->assertSame(['No audio stream detected — transcript skipped.'], $result['notes']);
        $this->assertNull($provider->lastAudioPath);
    }

    public function test_it_throws_when_there_is_no_video_stream(): void
    {
        Process::fake(function ($process) {
            $command = $process->command;

            if (in_array('-show_streams', $command, true)) {
                return Process::result(output: json_encode([
                    'format' => ['duration' => '5.0'],
                    'streams' => [['codec_type' => 'audio']],
                ]));
            }

            return Process::result(output: '');
        });

        $service = new ExtractReferenceMediaService(new FakeTranscriptionProvider);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No video stream found');

        $service->analyze('/fake/source.mp4', frameCount: 2, language: null, workDir: $this->workDir);
    }

    public function test_it_throws_a_readable_error_when_ffprobe_fails(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'invalid data found', exitCode: 1)]);

        $service = new ExtractReferenceMediaService(new FakeTranscriptionProvider);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ffprobe failed: invalid data found');

        $service->analyze('/fake/not-a-video.txt', frameCount: 2, language: null, workDir: $this->workDir);
    }
}
