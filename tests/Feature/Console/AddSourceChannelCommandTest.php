<?php

namespace Tests\Feature\Console;

use App\Domain\Source\Providers\FakeYoutubeDownloader;
use App\Domain\Source\YoutubeDownloaderInterface;
use App\Models\ContentProject;
use App\Models\Enums\SourceChannelFraming;
use App\Models\Enums\SourceChannelMode;
use App\Models\SourceChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddSourceChannelCommandTest extends TestCase
{
    use RefreshDatabase;

    private FakeYoutubeDownloader $downloader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->downloader = new FakeYoutubeDownloader;
        $this->app->instance(YoutubeDownloaderInterface::class, $this->downloader);
    }

    public function test_it_creates_a_source_channel_with_defaults(): void
    {
        $project = ContentProject::factory()->create();
        $this->downloader->respondWithChannelName('Cool Channel');

        $this->artisan('source:add', ['url' => 'https://www.youtube.com/@cool', '--project' => $project->id])
            ->expectsOutputToContain('(mode=highlights).')
            ->assertExitCode(0);

        $channel = SourceChannel::firstOrFail();
        $this->assertSame($project->id, $channel->content_project_id);
        $this->assertSame('https://www.youtube.com/@cool', $channel->url);
        $this->assertSame('Cool Channel', $channel->name);
        $this->assertSame(SourceChannelMode::Highlights, $channel->mode);
        $this->assertSame(SourceChannelFraming::BlurPad, $channel->framing);
        $this->assertSame(60, $channel->target_seconds);
        $this->assertSame(15, $channel->tolerance_seconds);
        $this->assertSame(3, $channel->max_clips);
        $this->assertSame(6, $channel->min_score);
        $this->assertSame(120, $channel->max_source_minutes);
    }

    public function test_it_creates_a_source_channel_with_custom_options(): void
    {
        $project = ContentProject::factory()->create();

        $this->artisan('source:add', [
            'url' => 'http://youtube.com/@x',
            '--project' => $project->id,
            '--mode' => 'fixed',
            '--target' => 45,
            '--tolerance' => 5,
            '--max-clips' => 2,
            '--min-score' => 8,
            '--max-source-minutes' => 30,
            '--framing' => 'crop',
        ])
            ->expectsOutputToContain('(mode=fixed)')
            ->assertExitCode(0);

        $channel = SourceChannel::firstOrFail();
        $this->assertSame(SourceChannelMode::Fixed, $channel->mode);
        $this->assertSame(SourceChannelFraming::Crop, $channel->framing);
        $this->assertSame(45, $channel->target_seconds);
        $this->assertSame(5, $channel->tolerance_seconds);
        $this->assertSame(2, $channel->max_clips);
        $this->assertSame(8, $channel->min_score);
        $this->assertSame(30, $channel->max_source_minutes);
        $this->assertNull($channel->name);
    }

    public function test_it_stores_null_name_when_name_lookup_throws(): void
    {
        $project = ContentProject::factory()->create();
        $this->app->instance(YoutubeDownloaderInterface::class, new class implements YoutubeDownloaderInterface
        {
            public function listRecent(string $channelUrl, int $limit): array
            {
                return [];
            }

            public function download(string $youtubeId, string $destinationPath): void {}

            public function channelName(string $channelUrl): ?string
            {
                throw new \RuntimeException('yt-dlp failed');
            }
        });

        $this->artisan('source:add', ['url' => 'https://youtube.com/@x', '--project' => $project->id])
            ->assertExitCode(0);

        $this->assertNull(SourceChannel::firstOrFail()->name);
    }

    public function test_it_fails_without_project_or_with_unknown_project(): void
    {
        $this->artisan('source:add', ['url' => 'https://youtube.com/@x'])
            ->expectsOutputToContain('--project')
            ->assertExitCode(1);

        $this->artisan('source:add', ['url' => 'https://youtube.com/@x', '--project' => 9999])
            ->expectsOutputToContain('does not exist')
            ->assertExitCode(1);

        $this->assertSame(0, SourceChannel::count());
    }

    public function test_it_fails_on_invalid_mode_or_framing(): void
    {
        $project = ContentProject::factory()->create();

        $this->artisan('source:add', ['url' => 'https://youtube.com/@x', '--project' => $project->id, '--mode' => 'bogus'])
            ->expectsOutputToContain('Invalid --mode')
            ->assertExitCode(1);

        $this->artisan('source:add', ['url' => 'https://youtube.com/@x', '--project' => $project->id, '--framing' => 'bogus'])
            ->expectsOutputToContain('Invalid --framing')
            ->assertExitCode(1);

        $this->assertSame(0, SourceChannel::count());
    }

    public function test_it_rejects_urls_that_are_not_http_or_https(): void
    {
        $project = ContentProject::factory()->create();

        foreach (['--exec=foo', 'ftp://x', '-o', 'youtube.com/@x'] as $url) {
            $this->artisan('source:add', ['url' => $url, '--project' => $project->id])
                ->expectsOutputToContain('http')
                ->assertExitCode(1);
        }

        $this->assertSame(0, SourceChannel::count());
    }

    public function test_it_rejects_out_of_range_numeric_options(): void
    {
        $project = ContentProject::factory()->create();

        $cases = [
            ['--min-score', '0'], ['--min-score', '11'], ['--min-score', 'abc'],
            ['--target', '0'], ['--tolerance', '-1'], ['--max-clips', '0'],
            ['--max-source-minutes', '0'], ['--target', '1.5'],
        ];

        foreach ($cases as [$option, $value]) {
            $this->artisan('source:add', ['url' => 'https://youtube.com/@x', '--project' => $project->id, $option => $value])
                ->expectsOutputToContain($option)
                ->assertExitCode(1);
        }

        $this->assertSame(0, SourceChannel::count());
    }

    public function test_it_rejects_empty_string_options_without_calling_the_downloader(): void
    {
        $project = ContentProject::factory()->create();
        $downloader = new class implements YoutubeDownloaderInterface
        {
            public int $calls = 0;

            public function listRecent(string $channelUrl, int $limit): array
            {
                return [];
            }

            public function download(string $youtubeId, string $destinationPath): void {}

            public function channelName(string $channelUrl): ?string
            {
                $this->calls++;

                return null;
            }
        };
        $this->app->instance(YoutubeDownloaderInterface::class, $downloader);

        foreach (['--mode', '--framing', '--target', '--tolerance', '--max-clips', '--min-score', '--max-source-minutes'] as $option) {
            $this->artisan('source:add', ['url' => 'https://youtube.com/@x', '--project' => $project->id, $option => ''])
                ->expectsOutputToContain($option)
                ->assertExitCode(1);
        }

        $this->assertSame(0, $downloader->calls);
        $this->assertSame(0, SourceChannel::count());
    }

    public function test_it_rejects_a_url_with_a_trailing_newline(): void
    {
        $project = ContentProject::factory()->create();

        $this->artisan('source:add', ['url' => "https://youtube.com/@x\n", '--project' => $project->id])
            ->expectsOutputToContain('http')
            ->assertExitCode(1);

        $this->assertSame(0, SourceChannel::count());
    }
}
