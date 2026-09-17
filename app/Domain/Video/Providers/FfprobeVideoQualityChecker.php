<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\QualityCheckResult;
use App\Domain\Video\VideoQualityCheckerInterface;
use App\Models\Video;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

final class FfprobeVideoQualityChecker implements VideoQualityCheckerInterface
{
    public function check(Video $video): QualityCheckResult
    {
        $disk = Storage::disk(config('filesystems.default'));
        $tempPath = tempnam(sys_get_temp_dir(), 'qc_').'.mp4';

        try {
            file_put_contents($tempPath, $disk->get($video->file_path));
            $probe = $this->probe($tempPath);
            $expectedDuration = $this->expectedDuration($video);

            $checks = [
                'has_video_stream' => $probe['has_video'],
                'has_audio_stream' => $probe['has_audio'],
                'resolution_matches' => $probe['width'] === config('render.resolution.width')
                    && $probe['height'] === config('render.resolution.height'),
                'duration_within_tolerance' => abs($probe['duration'] - $expectedDuration)
                    <= config('render.quality_check.duration_tolerance'),
                'not_excessively_black' => ! $this->hasExcessiveBlackFrames($tempPath),
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

    private function hasExcessiveBlackFrames(string $path): bool
    {
        $result = Process::timeout(config('render.timeout'))->run([
            config('render.ffmpeg_binary'), '-i', $path,
            '-vf', 'blackdetect=d=1:pic_th=0.98', '-an', '-f', 'null', '-',
        ]);

        return str_contains($result->errorOutput(), 'black_start');
    }
}
