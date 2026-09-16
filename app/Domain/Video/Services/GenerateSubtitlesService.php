<?php

namespace App\Domain\Video\Services;

use App\Domain\Video\Support\SrtFormatter;
use App\Domain\Video\TranscriptionProviderInterface;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class GenerateSubtitlesService
{
    public function __construct(private readonly TranscriptionProviderInterface $transcriptionProvider) {}

    /**
     * @return array{segments: array<int, array{start: float, end: float, text: string}>, language: string, srt: string}
     */
    public function generate(Video $video): array
    {
        $disk = Storage::disk(config('filesystems.default'));
        $tempPath = sys_get_temp_dir().'/voiceover_'.uniqid().'.mp3';

        try {
            $audio = $disk->get($video->voiceover->file_path)
                ?? throw new RuntimeException("Voiceover audio missing at {$video->voiceover->file_path}");

            file_put_contents($tempPath, $audio);
            $result = $this->transcriptionProvider->transcribe($tempPath, $video->contentProject->language);
        } finally {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
        }

        return [
            'segments' => $result->segments,
            'language' => $result->language,
            'srt' => SrtFormatter::format($result->segments),
            'metadata' => $result->metadata,
        ];
    }
}
