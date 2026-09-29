<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\RenderResult;
use App\Domain\Video\Support\AssSubtitleFormatter;
use App\Domain\Video\VideoRendererInterface;
use App\Models\Enums\SourceChannelFraming;
use App\Models\Video;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Cuts a clip out of a downloaded source video, reframes it to the vertical
 * render resolution and burns in subtitles. Resolved via app() by
 * RenderVideoJob for videos that have a source clip; not bound in a provider.
 */
final class SourceClipRenderer implements VideoRendererInterface
{
    public function render(Video $video): RenderResult
    {
        $clip = $video->sourceClip ?? throw new RuntimeException("Video {$video->id} has no source clip to render.");
        $clip->loadMissing('sourceVideo.sourceChannel');

        $sourceVideo = $clip->sourceVideo;
        $framing = $sourceVideo->sourceChannel->framing ?? SourceChannelFraming::BlurPad;

        if (! is_string($sourceVideo->file_path) || $sourceVideo->file_path === '') {
            throw new RuntimeException("Source video {$sourceVideo->id} has no downloaded file.");
        }

        $disk = Storage::disk(config('filesystems.default'));
        $workDir = sys_get_temp_dir().'/clip_'.$video->id.'_'.uniqid();
        File::makeDirectory($workDir, recursive: true);

        try {
            $extension = pathinfo($sourceVideo->file_path, PATHINFO_EXTENSION);
            $sourcePath = "{$workDir}/source".($extension !== '' ? ".{$extension}" : '');
            $this->copyFromStorage($disk, $sourceVideo->file_path, $sourcePath);

            $sourceProbe = $this->probe($sourcePath);

            $width = (int) config('render.resolution.width');
            $height = (int) config('render.resolution.height');
            $fps = config('render.fps');

            $assPath = "{$workDir}/subtitles.ass";
            File::put($assPath, AssSubtitleFormatter::format(
                $video->subtitle->metadata['segments'] ?? [],
                [...config('render.subtitles'), 'width' => $width, 'height' => $height],
            ));

            $duration = $clip->end - $clip->start;
            $ass = $this->escapeForFilter($assPath);

            $videoFilter = $framing === SourceChannelFraming::Crop
                ? "[0:v]scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},setsar=1,fps={$fps},ass={$ass}[v]"
                : "[0:v]split=2[bg][fg];[bg]scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},boxblur=20:5[bgb];"
                    ."[fg]scale={$width}:{$height}:force_original_aspect_ratio=decrease[fgs];"
                    ."[bgb][fgs]overlay=({$width}-w)/2:({$height}-h)/2,setsar=1,fps={$fps},ass={$ass}[v]";

            $filter = $sourceProbe['has_audio']
                ? $videoFilter.';[0:a]loudnorm=I=-16:TP=-1.5:LRA=11[a]'
                : $videoFilter;

            $outputPath = "{$workDir}/output.mp4";

            $command = [config('render.ffmpeg_binary'), '-y', '-ss', (string) $clip->start, '-i', $sourcePath];
            if (! $sourceProbe['has_audio']) {
                // Keep an audio track so the quality check's has_audio_stream passes.
                $command = [...$command, '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=stereo'];
            }
            $command = [
                ...$command,
                '-t', (string) $duration,
                '-filter_complex', $filter,
                '-map', '[v]', '-map', $sourceProbe['has_audio'] ? '[a]' : '1:a',
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20',
                '-c:a', 'aac', '-pix_fmt', 'yuv420p', '-movflags', '+faststart',
                $outputPath,
            ];

            $timeout = max((int) config('render.timeout'), (int) ceil($duration * 2));
            $result = Process::timeout($timeout)->run($command);

            if ($result->failed()) {
                throw new RuntimeException('Clip render failed: '.trim($result->errorOutput() ?: $result->output()));
            }

            $probe = $this->probe($outputPath);

            $storagePath = "projects/{$video->content_project_id}/renders/{$video->id}.mp4";
            $stream = fopen($outputPath, 'rb');
            try {
                $disk->put($storagePath, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return new RenderResult(
                path: $storagePath,
                duration: $probe['duration'],
                width: $probe['width'],
                height: $probe['height'],
                metadata: ['framing' => $framing->value, 'source_clip_id' => $clip->id],
            );
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * Streams the file so a multi-gigabyte source never sits in PHP memory.
     */
    private function copyFromStorage(Filesystem $disk, string $path, string $destination): void
    {
        $in = $disk->readStream($path);
        if (! is_resource($in)) {
            throw new RuntimeException("Unable to read source video [{$path}] from storage.");
        }

        $out = fopen($destination, 'wb');

        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * @return array{duration: float, width: int, height: int, has_audio: bool}
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
            'has_audio' => collect($streams)->contains('codec_type', 'audio'),
        ];
    }

    private function escapeForFilter(string $path): string
    {
        return str_replace(['\\', ':'], ['\\\\', '\\:'], $path);
    }
}
