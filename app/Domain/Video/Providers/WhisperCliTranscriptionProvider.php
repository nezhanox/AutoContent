<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\TranscriptionProviderInterface;
use App\Domain\Video\TranscriptionResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class WhisperCliTranscriptionProvider implements TranscriptionProviderInterface
{
    public function transcribe(string $audioPath, ?string $language = null): TranscriptionResult
    {
        $command = [
            config('whisper.python_binary'),
            config('whisper.script_path'),
            '--audio', $audioPath,
            '--model', config('whisper.model'),
        ];

        if ($language !== null) {
            $command[] = '--language';
            $command[] = $language;
        }

        $result = Process::timeout(config('whisper.timeout'))->run($command);

        if ($result->failed()) {
            throw new RuntimeException(
                'Whisper transcription failed: '.trim($result->errorOutput() ?: $result->output())
            );
        }

        $decoded = json_decode($result->output(), true);

        if (! is_array($decoded) || ! isset($decoded['segments']) || ! is_array($decoded['segments'])) {
            throw new RuntimeException('Whisper transcription returned invalid JSON: '.$result->output());
        }

        return new TranscriptionResult(
            segments: array_map(static fn (array $segment): array => [
                'start' => (float) $segment['start'],
                'end' => (float) $segment['end'],
                'text' => trim((string) $segment['text']),
            ], $decoded['segments']),
            language: (string) ($decoded['language'] ?? $language ?? 'en'),
            metadata: ['model' => config('whisper.model')],
        );
    }
}
