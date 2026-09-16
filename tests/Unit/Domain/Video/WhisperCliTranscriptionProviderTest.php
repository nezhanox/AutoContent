<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\Providers\WhisperCliTranscriptionProvider;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class WhisperCliTranscriptionProviderTest extends TestCase
{
    public function test_it_parses_a_successful_transcription(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode([
                'language' => 'en',
                'segments' => [
                    ['start' => 0.0, 'end' => 2.4, 'text' => '  This AI just changed coding  '],
                ],
            ])),
        ]);

        $provider = new WhisperCliTranscriptionProvider;
        $result = $provider->transcribe('/tmp/audio.mp3', 'en');

        $this->assertSame('en', $result->language);
        $this->assertSame([
            ['start' => 0.0, 'end' => 2.4, 'text' => 'This AI just changed coding'],
        ], $result->segments);

        Process::assertRan(function ($process) {
            return in_array('--audio', $process->command, true)
                && in_array('/tmp/audio.mp3', $process->command, true)
                && in_array('--language', $process->command, true)
                && in_array('en', $process->command, true);
        });
    }

    public function test_it_omits_the_language_flag_when_no_language_is_given(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode(['language' => 'en', 'segments' => []])),
        ]);

        $provider = new WhisperCliTranscriptionProvider;
        $provider->transcribe('/tmp/audio.mp3');

        Process::assertRan(function ($process) {
            return ! in_array('--language', $process->command, true);
        });
    }

    public function test_it_throws_with_the_error_output_when_the_process_fails(): void
    {
        Process::fake([
            '*' => Process::result(errorOutput: 'model not found', exitCode: 1),
        ]);

        $provider = new WhisperCliTranscriptionProvider;

        try {
            $provider->transcribe('/tmp/audio.mp3');
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('model not found', $exception->getMessage());
        }
    }

    public function test_it_throws_when_the_output_is_not_valid_json(): void
    {
        Process::fake([
            '*' => Process::result(output: 'not json'),
        ]);

        $provider = new WhisperCliTranscriptionProvider;

        try {
            $provider->transcribe('/tmp/audio.mp3');
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('invalid JSON', $exception->getMessage());
        }
    }

    public function test_it_uses_the_configured_python_binary_script_model_and_timeout(): void
    {
        config()->set('whisper.python_binary', 'python3.11');
        config()->set('whisper.script_path', '/opt/whisper/transcribe.py');
        config()->set('whisper.model', 'small');
        config()->set('whisper.timeout', 900);

        Process::fake([
            '*' => Process::result(output: json_encode(['language' => 'en', 'segments' => []])),
        ]);

        $provider = new WhisperCliTranscriptionProvider;
        $provider->transcribe('/tmp/audio.mp3');

        Process::assertRan(function ($process) {
            return $process->command === [
                'python3.11', '/opt/whisper/transcribe.py', '--audio', '/tmp/audio.mp3', '--model', 'small',
            ] && $process->timeout === 900;
        });
    }
}
