<?php

namespace Tests\Unit\Domain\Source;

use App\Domain\Source\Providers\FakeYoutubeDownloader;
use App\Domain\Source\Providers\YtDlpDownloader;
use App\Domain\Source\YoutubeDownloaderInterface;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class YtDlpDownloaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'clips.yt_dlp_binary' => 'yt-dlp',
            'clips.yt_dlp_format' => 'best',
            'clips.yt_dlp_cookies_file' => null,
            'clips.yt_dlp_timeout' => 100,
        ]);
    }

    public function test_it_lists_recent_videos_and_parses_output(): void
    {
        Process::fake(['*' => Process::result("abc123|||Title one|||125\nxyz789|||Title two|||NA\n")]);

        $videos = (new YtDlpDownloader)->listRecent('https://www.youtube.com/@foo', 5);

        $this->assertSame([
            ['id' => 'abc123', 'title' => 'Title one', 'duration' => 125.0],
            ['id' => 'xyz789', 'title' => 'Title two', 'duration' => 0.0],
        ], $videos);

        Process::assertRan(fn ($p) => $p->command[0] === 'yt-dlp'
            && in_array('--flat-playlist', $p->command, true)
            && in_array('--playlist-end', $p->command, true)
            && in_array('5', $p->command, true)
            && end($p->command) === 'https://www.youtube.com/@foo/videos');
    }

    public function test_it_does_not_change_url_already_ending_with_videos(): void
    {
        Process::fake(['*' => Process::result('')]);

        $this->assertSame([], (new YtDlpDownloader)->listRecent('https://www.youtube.com/@foo/videos', 3));

        Process::assertRan(fn ($p) => end($p->command) === 'https://www.youtube.com/@foo/videos');
    }

    public function test_it_appends_videos_to_channel_id_urls(): void
    {
        Process::fake(['*' => Process::result('')]);

        (new YtDlpDownloader)->listRecent('https://www.youtube.com/channel/UC123/', 3);

        Process::assertRan(fn ($p) => end($p->command) === 'https://www.youtube.com/channel/UC123/videos');
    }

    public function test_it_throws_when_listing_fails(): void
    {
        Process::fake(['*' => Process::result('', 'boom', 1)]);

        $this->expectException(RuntimeException::class);

        (new YtDlpDownloader)->listRecent('https://www.youtube.com/@foo', 5);
    }

    public function test_it_downloads_video(): void
    {
        Process::fake(['*' => Process::result('')]);

        (new YtDlpDownloader)->download('abc123', '/tmp/out.mp4');

        Process::assertRan(function ($p) {
            $c = $p->command;

            return in_array('-f', $c, true)
                && in_array('best', $c, true)
                && in_array('--merge-output-format', $c, true)
                && in_array('mp4', $c, true)
                && in_array('/tmp/out.mp4', $c, true)
                && end($c) === 'https://www.youtube.com/watch?v=abc123';
        });
    }

    public function test_it_throws_when_download_fails(): void
    {
        Process::fake(['*' => Process::result('', 'nope', 1)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('yt-dlp download failed');

        (new YtDlpDownloader)->download('abc123', '/tmp/out.mp4');
    }

    public function test_it_returns_trimmed_channel_name(): void
    {
        Process::fake(['*' => Process::result("  My Channel \n")]);

        $this->assertSame('My Channel', (new YtDlpDownloader)->channelName('https://www.youtube.com/@foo'));
    }

    public function test_it_returns_null_channel_name_for_na_or_failure(): void
    {
        Process::fake(['*' => Process::result("NA\n")]);
        $this->assertNull((new YtDlpDownloader)->channelName('https://www.youtube.com/@foo'));

        Process::fake(['*' => Process::result('', 'err', 1)]);
        $this->assertNull((new YtDlpDownloader)->channelName('https://www.youtube.com/@foo'));
    }

    public function test_it_adds_cookies_file_when_configured(): void
    {
        config(['clips.yt_dlp_cookies_file' => '/cookies.txt']);
        Process::fake(['*' => Process::result('')]);

        (new YtDlpDownloader)->download('abc123', '/tmp/out.mp4');

        Process::assertRan(function ($p) {
            $i = array_search('--cookies', $p->command, true);

            return $i !== false && $p->command[$i + 1] === '/cookies.txt';
        });
    }

    public function test_fake_writes_file_and_records_download(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'yt');
        $fake = new FakeYoutubeDownloader;

        $fake->download('abc', $path);

        $this->assertSame('fake-source-video', file_get_contents($path));
        $this->assertSame(['abc'], $fake->downloaded);
        unlink($path);
    }

    public function test_fake_can_fail_downloads_and_return_configured_data(): void
    {
        $fake = (new FakeYoutubeDownloader)
            ->respondWithVideos([['id' => 'a', 'title' => 'T', 'duration' => 1.0]])
            ->respondWithChannelName('Chan')
            ->failDownloadsWith('bad');

        $this->assertCount(1, $fake->listRecent('u', 5));
        $this->assertSame('Chan', $fake->channelName('u'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bad');
        $fake->download('a', '/tmp/x');
    }

    public function test_it_resolves_from_container(): void
    {
        $this->assertInstanceOf(YtDlpDownloader::class, app(YoutubeDownloaderInterface::class));
    }
}
