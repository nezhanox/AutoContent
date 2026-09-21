<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\AudioProbeInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class FfprobeAudioProbe implements AudioProbeInterface
{
    public function duration(string $audioContent): float
    {
        $path = tempnam(sys_get_temp_dir(), 'audio_probe_').'.mp3';
        File::put($path, $audioContent);

        try {
            $result = Process::timeout(config('render.timeout'))->run([
                config('render.ffprobe_binary'), '-v', 'quiet', '-print_format', 'json', '-show_format', $path,
            ]);

            if ($result->failed()) {
                throw new RuntimeException('ffprobe failed: '.trim($result->errorOutput() ?: $result->output()));
            }

            $decoded = json_decode($result->output(), true);

            return (float) ($decoded['format']['duration'] ?? 0.0);
        } finally {
            File::delete($path);
        }
    }
}
