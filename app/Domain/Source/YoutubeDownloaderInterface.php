<?php

namespace App\Domain\Source;

interface YoutubeDownloaderInterface
{
    /**
     * @return list<array{id:string,title:string,duration:float}> newest first; duration is 0.0 when unknown
     */
    public function listRecent(string $channelUrl, int $limit): array;

    /**
     * Downloads the video into $destinationPath (mp4).
     *
     * @throws \RuntimeException
     */
    public function download(string $youtubeId, string $destinationPath): void;

    public function channelName(string $channelUrl): ?string;
}
