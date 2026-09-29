<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\QualityCheckResult;
use App\Domain\Video\VideoQualityCheckerInterface;
use App\Models\Video;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class FfprobeVideoQualityChecker implements VideoQualityCheckerInterface
{
    public function check(Video $video): QualityCheckResult
    {
        $disk = Storage::disk(config('filesystems.default'));
        $stub = tempnam(sys_get_temp_dir(), 'qc_');
        $tempPath = $stub.'.mp4';

        try {
            $this->copyToTemp($disk, $video->file_path, $tempPath);
            $probe = $this->probe($tempPath);
            $expectedDuration = $this->expectedDuration($video);

            $checks = [
                'has_video_stream' => $probe['has_video'],
                'has_audio_stream' => $probe['has_audio'],
                'resolution_matches' => $probe['width'] === config('render.resolution.width')
                    && $probe['height'] === config('render.resolution.height'),
                'duration_within_tolerance' => abs($probe['duration'] - $expectedDuration)
                    <= config('render.quality_check.duration_tolerance'),
                'not_excessively_black' => ! $this->hasExcessiveBlackFrames($tempPath, $probe['duration']),
            ];

            return new QualityCheckResult(
                passed: ! in_array(false, $checks, true),
                checks: $checks,
                metadata: $probe,
            );
        } finally {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
            if (is_file($stub)) {
                unlink($stub);
            }
        }
    }

    /**
     * Mirrors FfmpegVideoRenderer's actual output duration: crossfade
     * transitions overlap adjacent scenes, so each of the (n-1) transitions
     * between scenes shortens the combined runtime by the transition
     * duration. Comparing against the naive sum of scene durations would
     * make this check fail on correctly-rendered videos as scene count grows.
     */
    private function expectedDuration(Video $video): float
    {
        $scenes = $video->scenes;
        $expectedDuration = (float) $scenes->sum('duration');

        if (config('render.transition.type') !== 'none' && $scenes->count() >= 2) {
            $expectedDuration -= ($scenes->count() - 1) * (float) config('render.transition.duration');
        }

        return $expectedDuration;
    }

    /**
     * @return array{duration: float, width: int, height: int, has_video: bool, has_audio: bool}
     */
    private function probe(string $path): array
    {
        $result = Process::timeout(config('render.timeout'))->run([
            config('render.ffprobe_binary'), '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $path,
        ]);

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

    /**
     * Stream the rendered file into a temp file instead of loading it into memory (whole-mode renders can be hundreds of MB).
     */
    private function copyToTemp(Filesystem $disk, string $path, string $tempPath): void
    {
        $source = $disk->readStream($path);

        if (! is_resource($source)) {
            throw new RuntimeException("Unable to open rendered file [{$path}] for quality check.");
        }

        $target = fopen($tempPath, 'wb');

        if ($target === false) {
            fclose($source);

            throw new RuntimeException("Unable to open temp file [{$tempPath}] for quality check.");
        }

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    private function hasExcessiveBlackFrames(string $path, float $duration): bool
    {
        // blackdetect decodes the whole file, so the time budget must scale with the video length.
        $timeout = max((int) config('render.timeout'), (int) ceil($duration * 2));

        $result = Process::timeout($timeout)->run([
            config('render.ffmpeg_binary'), '-i', $path,
            '-vf', 'blackdetect=d=1:pic_th=0.98', '-an', '-f', 'null', '-',
        ]);

        return str_contains($result->errorOutput(), 'black_start');
    }
}
