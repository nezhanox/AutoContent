<?php

namespace App\Console\Commands;

use App\Domain\Video\Services\ExtractReferenceMediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class AnalyzeVideoReferenceCommand extends Command
{
    protected $signature = 'video-reference:analyze
        {path : Path to a local reference video file}
        {--frames=8 : Number of evenly-spaced frames to extract}
        {--language= : ISO language hint for transcription, e.g. en/uk}';

    protected $description = 'Extract frames, audio transcript, and metadata from a local reference video (no DB writes).';

    public function handle(ExtractReferenceMediaService $service): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: [{$path}].");

            return self::FAILURE;
        }

        $frameCount = (int) $this->option('frames');

        if ($frameCount < 1 || $frameCount > 30) {
            $this->error('--frames must be between 1 and 30.');

            return self::FAILURE;
        }

        $workDir = storage_path('app/private/reference-analysis/'.(string) Str::uuid());
        File::makeDirectory($workDir, recursive: true);

        try {
            $result = $service->analyze(
                sourcePath: $path,
                frameCount: $frameCount,
                language: $this->option('language'),
                workDir: $workDir,
            );
        } catch (RuntimeException $exception) {
            $this->error("Reference video analysis failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $result['frames'] = array_map(
            fn (string $framePath): string => Str::after($framePath, base_path().DIRECTORY_SEPARATOR),
            $result['frames'],
        );

        $output = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        if ($output === false) {
            $this->error('Failed to encode analysis output as JSON.');

            return self::FAILURE;
        }

        $this->line($output);

        return self::SUCCESS;
    }
}
