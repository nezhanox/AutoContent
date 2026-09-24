<?php

namespace App\Domain\Video\Services;

use App\Domain\Video\TranscriptionProviderInterface;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class ExtractReferenceMediaService
{
    public function __construct(private readonly TranscriptionProviderInterface $transcriptionProvider) {}

    /**
     * @return array{
     *     duration: float,
     *     width: int,
     *     height: int,
     *     frames: array<int, string>,
     *     transcript: array{language: string, segments: array<int, array{start: float, end: float, text: string}>}|null,
     *     notes: array<int, string>,
     * }
     */
    public function analyze(string $sourcePath, int $frameCount, ?string $language, string $workDir): array
    {
        $probe = $this->probe($sourcePath);

        if (! $probe['has_video']) {
            throw new RuntimeException("No video stream found in [{$sourcePath}].");
        }

        $notes = [];
        $transcript = null;

        if ($probe['has_audio']) {
            $audioPath = "{$workDir}/audio.wav";
            $this->extractAudio($sourcePath, $audioPath);
            $result = $this->transcriptionProvider->transcribe($audioPath, $language);

            $transcript = [
                'language' => $result->language,
                'segments' => $result->segments,
            ];
        } else {
            $notes[] = 'No audio stream detected — transcript skipped.';
        }

        return [
            'duration' => $probe['duration'],
            'width' => $probe['width'],
            'height' => $probe['height'],
            'frames' => $this->extractFrames($sourcePath, $probe['duration'], $frameCount, $workDir),
            'transcript' => $transcript,
            'notes' => $notes,
        ];
    }

    /**
     * @return array{duration: float, width: int, height: int, has_video: bool, has_audio: bool}
     */
    private function probe(string $path): array
    {
        $result = Process::timeout(config('render.timeout'))->run([
            config('render.ffprobe_binary'), '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $path,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('ffprobe failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        $decoded = json_decode($result->output(), true);
        $streams = is_array($decoded['streams'] ?? null) ? $decoded['streams'] : [];
        $videoStream = collect($streams)->firstWhere('codec_type', 'video');
        $audioStream = collect($streams)->firstWhere('codec_type', 'audio');

        return [
            'duration' => (float) ($decoded['format']['duration'] ?? 0.0),
            'width' => (int) ($videoStream['width'] ?? 0),
            'height' => (int) ($videoStream['height'] ?? 0),
            'has_video' => $videoStream !== null,
            'has_audio' => $audioStream !== null,
        ];
    }

    private function extractAudio(string $sourcePath, string $audioPath): void
    {
        $result = Process::timeout(config('render.timeout'))->run([
            config('render.ffmpeg_binary'), '-y', '-i', $sourcePath,
            '-vn', '-acodec', 'pcm_s16le', '-ar', '16000', '-ac', '1',
            $audioPath,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('ffmpeg audio extraction failed: '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    /**
     * @return array<int, string>
     */
    private function extractFrames(string $sourcePath, float $duration, int $frameCount, string $workDir): array
    {
        $frames = [];

        for ($i = 0; $i < $frameCount; $i++) {
            $timestamp = $duration * ($i + 0.5) / $frameCount;
            $framePath = sprintf('%s/frame-%02d.jpg', $workDir, $i + 1);

            $result = Process::timeout(config('render.timeout'))->run([
                config('render.ffmpeg_binary'), '-y', '-ss', (string) $timestamp, '-i', $sourcePath,
                '-frames:v', '1', '-q:v', '2', $framePath,
            ]);

            if ($result->failed()) {
                throw new RuntimeException("ffmpeg frame extraction failed at {$timestamp}s: ".trim($result->errorOutput() ?: $result->output()));
            }

            $frames[] = $framePath;
        }

        return $frames;
    }
}
