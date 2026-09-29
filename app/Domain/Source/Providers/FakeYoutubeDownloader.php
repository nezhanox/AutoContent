<?php

namespace App\Domain\Source\Providers;

use App\Domain\Source\YoutubeDownloaderInterface;
use RuntimeException;

final class FakeYoutubeDownloader implements YoutubeDownloaderInterface
{
    /** @var list<string> */
    public array $downloaded = [];

    /** @var list<array{id:string,title:string,duration:float}> */
    private array $videos = [];

    private ?string $channelName = null;

    private ?string $downloadFailure = null;

    /**
     * @param  list<array{id:string,title:string,duration:float}>  $videos
     */
    public function respondWithVideos(array $videos): static
    {
        $this->videos = $videos;

        return $this;
    }

    public function respondWithChannelName(?string $name): static
    {
        $this->channelName = $name;

        return $this;
    }

    public function failDownloadsWith(?string $message): static
    {
        $this->downloadFailure = $message;

        return $this;
    }

    public function listRecent(string $channelUrl, int $limit): array
    {
        return array_slice($this->videos, 0, $limit);
    }

    public function download(string $youtubeId, string $destinationPath): void
    {
        if ($this->downloadFailure !== null) {
            throw new RuntimeException($this->downloadFailure);
        }

        file_put_contents($destinationPath, 'fake-source-video');
        $this->downloaded[] = $youtubeId;
    }

    public function channelName(string $channelUrl): ?string
    {
        return $this->channelName;
    }
}
