<?php

namespace Tests\Feature\Console;

use App\Domain\Video\Providers\FakeTranscriptionProvider;
use App\Domain\Video\TranscriptionProviderInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class AnalyzeVideoReferenceCommandTest extends TestCase
{
    private string $sourcePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourcePath = sys_get_temp_dir().'/reference_command_test_'.uniqid().'.mp4';
        File::put($this->sourcePath, 'fake-video-bytes');
    }

    protected function tearDown(): void
    {
        if (is_file($this->sourcePath)) {
            unlink($this->sourcePath);
        }

        File::deleteDirectory(storage_path('app/private/reference-analysis'));

        parent::tearDown();
    }

    private function fakeFfmpegProcesses(bool $withAudio = true, float $duration = 10.0): void
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

    public function test_it_outputs_relative_frame_paths_and_transcript_json(): void
    {
        $this->fakeFfmpegProcesses(withAudio: true, duration: 10.0);
        $this->app->bind(TranscriptionProviderInterface::class, function () {
            return (new FakeTranscriptionProvider)->respondWith(
                [['start' => 0.0, 'end' => 1.0, 'text' => 'Hi there']],
                'en'
            );
        });

        $exitCode = Artisan::call('video-reference:analyze', [
            'path' => $this->sourcePath,
            '--frames' => 5,
        ]);

        $this->assertSame(0, $exitCode);

        $output = json_decode(Artisan::output(), true);

        $this->assertSame(10.0, $output['duration']);
        $this->assertSame(1080, $output['width']);
        $this->assertSame(1920, $output['height']);
        $this->assertCount(5, $output['frames']);

        foreach ($output['frames'] as $framePath) {
            $this->assertStringStartsWith('storage/app/private/reference-analysis/', $framePath);
        }

        $this->assertSame('en', $output['transcript']['language']);
        $this->assertSame('Hi there', $output['transcript']['segments'][0]['text']);
        $this->assertSame([], $output['notes']);
    }

    public function test_it_reports_a_note_and_null_transcript_when_there_is_no_audio_stream(): void
    {
        $this->fakeFfmpegProcesses(withAudio: false, duration: 6.0);

        $exitCode = Artisan::call('video-reference:analyze', [
            'path' => $this->sourcePath,
        ]);

        $this->assertSame(0, $exitCode);

        $output = json_decode(Artisan::output(), true);

        $this->assertNull($output['transcript']);
        $this->assertSame(['No audio stream detected — transcript skipped.'], $output['notes']);
    }

    public function test_it_fails_for_a_missing_file(): void
    {
        $exitCode = Artisan::call('video-reference:analyze', [
            'path' => '/no/such/file.mp4',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('File not found', Artisan::output());
    }

    public function test_it_rejects_an_out_of_range_frame_count(): void
    {
        $exitCode = Artisan::call('video-reference:analyze', [
            'path' => $this->sourcePath,
            '--frames' => 0,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--frames must be between 1 and 30', Artisan::output());
    }

    public function test_it_fails_with_a_readable_message_when_ffprobe_rejects_the_file(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'invalid data found when processing input', exitCode: 1)]);

        $exitCode = Artisan::call('video-reference:analyze', [
            'path' => $this->sourcePath,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Reference video analysis failed', Artisan::output());
    }
}
