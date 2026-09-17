<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\RenderResult;
use App\Domain\Video\Support\AssSubtitleFormatter;
use App\Domain\Video\VideoRendererInterface;
use App\Models\Enums\MediaAssetType;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class FfmpegVideoRenderer implements VideoRendererInterface
{
    public function render(Video $video): RenderResult
    {
        $disk = Storage::disk(config('filesystems.default'));
        $workDir = sys_get_temp_dir().'/render_'.$video->id.'_'.uniqid();
        File::makeDirectory($workDir, recursive: true);

        try {
            $sceneClips = [];
            $durations = [];

            foreach ($video->scenes as $index => $scene) {
                $sceneClips[] = $this->normalizeScene($scene, $disk, $workDir, $index);
                $durations[] = (float) $scene->duration;
            }

            $totalDuration = array_sum($durations);
            if (config('render.transition.type') !== 'none' && count($sceneClips) >= 2) {
                $totalDuration -= (count($sceneClips) - 1) * (float) config('render.transition.duration');
            }
            $concatPath = $this->concatenateClips($sceneClips, $durations, $workDir);

            $assPath = "{$workDir}/subtitles.ass";
            File::put($assPath, AssSubtitleFormatter::format(
                $video->subtitle->metadata['segments'] ?? [],
                [
                    ...config('render.subtitles'),
                    'width' => config('render.resolution.width'),
                    'height' => config('render.resolution.height'),
                ],
            ));

            $voicePath = $this->materialize($disk, $video->voiceover->file_path, $workDir, 'voice.mp3');
            $musicPath = $video->musicAsset !== null
                ? $this->materialize($disk, $video->musicAsset->path, $workDir, 'music.mp3')
                : null;

            $outputPath = "{$workDir}/output.mp4";
            $this->mixAndBurn($concatPath, $assPath, $voicePath, $musicPath, $totalDuration, $outputPath);

            $probe = $this->probe($outputPath);

            $storagePath = "projects/{$video->content_project_id}/renders/{$video->id}.mp4";
            $disk->put($storagePath, file_get_contents($outputPath));

            return new RenderResult(
                path: $storagePath,
                duration: $probe['duration'],
                width: $probe['width'],
                height: $probe['height'],
                metadata: ['transition' => config('render.transition.type')],
            );
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    private function normalizeScene(VideoScene $scene, Filesystem $disk, string $workDir, int $index): string
    {
        $asset = $scene->asset;
        $source = $this->materialize($disk, $asset->path, $workDir, "scene_{$index}_src".$this->extension($asset->path));
        $output = "{$workDir}/scene_{$index}.mp4";

        $width = config('render.resolution.width');
        $height = config('render.resolution.height');
        $fps = config('render.fps');
        $vf = "scale={$width}:{$height}:force_original_aspect_ratio=decrease,"
            ."pad={$width}:{$height}:(ow-iw)/2:(oh-ih)/2,setsar=1,fps={$fps}";

        $isImage = in_array($asset->type, [MediaAssetType::Image, MediaAssetType::Thumbnail], true);

        $command = $isImage
            ? [$this->binary(), '-y', '-loop', '1', '-i', $source, '-t', (string) $scene->duration]
            : [$this->binary(), '-y', '-stream_loop', '-1', '-i', $source, '-t', (string) $scene->duration];

        $command = [...$command, '-vf', $vf, '-r', (string) $fps, '-an', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $output];

        $result = Process::timeout(config('render.timeout'))->run($command);

        if ($result->failed()) {
            throw new RuntimeException("Scene {$index} normalization failed: ".trim($result->errorOutput() ?: $result->output()));
        }

        return $output;
    }

    /**
     * @param  array<int, string>  $clips
     * @param  array<int, float>  $durations
     */
    private function concatenateClips(array $clips, array $durations, string $workDir): string
    {
        $output = "{$workDir}/concat.mp4";

        if (config('render.transition.type') === 'none' || count($clips) < 2) {
            return $this->concatenateWithoutTransition($clips, $workDir, $output);
        }

        $inputs = [];
        foreach ($clips as $clip) {
            $inputs[] = '-i';
            $inputs[] = $clip;
        }

        $transitionType = config('render.transition.type');
        $transitionDuration = (float) config('render.transition.duration');

        $filters = [];
        $cumulative = $durations[0];
        $currentLabel = '0';

        for ($i = 1; $i < count($clips); $i++) {
            $offset = max(0.0, $cumulative - ($i * $transitionDuration));
            $nextLabel = "v{$i}";
            $filters[] = "[{$currentLabel}][{$i}]xfade=transition={$transitionType}:duration={$transitionDuration}:offset={$offset}[{$nextLabel}]";
            $cumulative += $durations[$i];
            $currentLabel = $nextLabel;
        }

        $command = [
            $this->binary(), '-y', ...$inputs,
            '-filter_complex', implode(';', $filters),
            '-map', "[{$currentLabel}]",
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p',
            $output,
        ];

        $result = Process::timeout(config('render.timeout'))->run($command);

        if ($result->failed()) {
            throw new RuntimeException('Scene concatenation failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        return $output;
    }

    /**
     * @param  array<int, string>  $clips
     */
    private function concatenateWithoutTransition(array $clips, string $workDir, string $output): string
    {
        $listPath = "{$workDir}/concat_list.txt";
        File::put($listPath, implode("\n", array_map(
            static fn (string $clip): string => "file '{$clip}'",
            $clips,
        )));

        $result = Process::timeout(config('render.timeout'))->run([
            $this->binary(), '-y', '-f', 'concat', '-safe', '0', '-i', $listPath, '-c', 'copy', $output,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Scene concatenation failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        return $output;
    }

    private function mixAndBurn(string $videoPath, string $assPath, string $voicePath, ?string $musicPath, float $duration, string $output): void
    {
        $inputs = ['-i', $videoPath, '-i', $voicePath];
        if ($musicPath !== null) {
            $inputs = [...$inputs, '-i', $musicPath];
        }

        $voiceVolume = config('render.audio.voice_volume');
        $musicVolume = config('render.audio.music_volume');

        $audioFilter = $musicPath !== null
            ? "[1:a]volume={$voiceVolume}[a1];[2:a]aloop=loop=-1:size=2e9,volume={$musicVolume}[a2];"
                .'[a1][a2]amix=inputs=2:duration=first:dropout_transition=0:normalize=0[a]'
            : "[1:a]volume={$voiceVolume}[a]";

        $filterComplex = "[0:v]ass={$this->escapeForFilter($assPath)}[v];{$audioFilter}";

        $command = [
            $this->binary(), '-y', ...$inputs,
            '-filter_complex', $filterComplex,
            '-map', '[v]', '-map', '[a]',
            '-t', (string) $duration,
            '-c:v', 'libx264', '-c:a', 'aac', '-pix_fmt', 'yuv420p',
            $output,
        ];

        $result = Process::timeout(config('render.timeout'))->run($command);

        if ($result->failed()) {
            throw new RuntimeException('Final render mux failed: '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    /**
     * @return array{duration: float, width: int, height: int}
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

        return [
            'duration' => (float) ($decoded['format']['duration'] ?? 0.0),
            'width' => (int) ($videoStream['width'] ?? 0),
            'height' => (int) ($videoStream['height'] ?? 0),
        ];
    }

    private function materialize(Filesystem $disk, string $path, string $workDir, string $filename): string
    {
        $destination = "{$workDir}/{$filename}";
        File::put($destination, $disk->get($path));

        return $destination;
    }

    private function extension(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension !== '' ? ".{$extension}" : '';
    }

    private function escapeForFilter(string $path): string
    {
        return str_replace(['\\', ':'], ['\\\\', '\\:'], $path);
    }

    private function binary(): string
    {
        return config('render.ffmpeg_binary');
    }
}
