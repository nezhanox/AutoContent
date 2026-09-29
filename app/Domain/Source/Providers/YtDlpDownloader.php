<?php

namespace App\Domain\Source\Providers;

use App\Domain\Source\YoutubeDownloaderInterface;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class YtDlpDownloader implements YoutubeDownloaderInterface
{
    public function listRecent(string $channelUrl, int $limit): array
    {
        $command = $this->base();
        array_push(
            $command,
            '--flat-playlist',
            '--playlist-end', (string) $limit,
            '--print', '%(id)s|||%(title)s|||%(duration)s',
            $this->normalizeChannelUrl($channelUrl),
        );

        $result = Process::timeout(config('clips.yt_dlp_timeout'))->run($command);

        if ($result->failed()) {
            throw new RuntimeException(
                'yt-dlp listing failed: '.trim($result->errorOutput() ?: $result->output())
            );
        }

        $videos = [];

        foreach (preg_split('/\R/', $result->output()) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = explode('|||', $line, 3);

            if (count($parts) < 2 || $parts[0] === '') {
                continue;
            }

            $duration = trim($parts[2] ?? '');

            $videos[] = [
                'id' => $parts[0],
                'title' => $parts[1],
                'duration' => is_numeric($duration) ? (float) $duration : 0.0,
            ];
        }

        return $videos;
    }

    public function download(string $youtubeId, string $destinationPath): void
    {
        $command = $this->base();
        array_push(
            $command,
            '-f', config('clips.yt_dlp_format'),
            '--merge-output-format', 'mp4',
            '--no-playlist',
            '--no-progress',
            '-o', $destinationPath,
            'https://www.youtube.com/watch?v='.$youtubeId,
        );

        $result = Process::timeout(config('clips.yt_dlp_timeout'))->run($command);

        if ($result->failed()) {
            throw new RuntimeException(
                'yt-dlp download failed: '.trim($result->errorOutput() ?: $result->output())
            );
        }
    }

    public function channelName(string $channelUrl): ?string
    {
        $command = $this->base();
        array_push(
            $command,
            '--flat-playlist',
            '--playlist-items', '1',
            '--print', '%(playlist_channel,playlist_uploader)s',
            $this->normalizeChannelUrl($channelUrl),
        );

        try {
            $result = Process::timeout(config('clips.yt_dlp_timeout'))->run($command);
        } catch (\Throwable) {
            return null;
        }

        if ($result->failed()) {
            return null;
        }

        $name = trim(strtok(trim($result->output()), "\n") ?: '');

        return $name === '' || $name === 'NA' ? null : $name;
    }

    /**
     * @return list<string>
     */
    private function base(): array
    {
        $command = [config('clips.yt_dlp_binary')];

        if (config('clips.yt_dlp_cookies_file') !== null) {
            $command[] = '--cookies';
            $command[] = config('clips.yt_dlp_cookies_file');
        }

        return $command;
    }

    /**
     * Point channel URLs at the /videos tab so Shorts/Live are not listed.
     */
    private function normalizeChannelUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');

        if (preg_match('#/(videos|shorts|streams|playlists)$#', $url)) {
            return $url;
        }

        if (preg_match('#/(@[^/]+|channel/[^/]+|c/[^/]+|user/[^/]+)$#', $url)) {
            return $url.'/videos';
        }

        return $url;
    }
}
