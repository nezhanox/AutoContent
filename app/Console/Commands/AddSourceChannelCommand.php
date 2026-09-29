<?php

namespace App\Console\Commands;

use App\Domain\Source\YoutubeDownloaderInterface;
use App\Models\ContentProject;
use App\Models\Enums\SourceChannelFraming;
use App\Models\Enums\SourceChannelMode;
use App\Models\SourceChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class AddSourceChannelCommand extends Command
{
    protected $signature = 'source:add
        {url : YouTube channel URL (http/https only)}
        {--project= : ContentProject id (required)}
        {--mode=highlights : whole|fixed|highlights}
        {--target=60 : Target clip length in seconds}
        {--tolerance=15 : Allowed deviation from target in seconds}
        {--max-clips=3 : Max clips per source video}
        {--min-score=6 : Minimum LLM score (1-10) for a clip}
        {--max-source-minutes=120 : Skip source videos longer than this}
        {--framing=blur_pad : blur_pad|crop}';

    protected $description = 'Register a YouTube channel as a clip source for a ContentProject.';

    public function handle(YoutubeDownloaderInterface $downloader): int
    {
        $url = (string) $this->argument('url');

        $validator = Validator::make([
            'url' => $url,
            'project' => $this->option('project'),
            'mode' => $this->option('mode'),
            'framing' => $this->option('framing'),
            'target' => $this->option('target'),
            'tolerance' => $this->option('tolerance'),
            'max-clips' => $this->option('max-clips'),
            'min-score' => $this->option('min-score'),
            'max-source-minutes' => $this->option('max-source-minutes'),
        ], [
            // The url is later passed to yt-dlp as a bare argument, so anything
            // not starting with http(s):// (e.g. "--exec=...") must be rejected.
            'url' => ['required', 'string', 'regex:/^https?:\/\/\S+$/i'],
            'project' => ['required', 'integer', Rule::exists('content_projects', 'id')],
            'mode' => [Rule::in(array_column(SourceChannelMode::cases(), 'value'))],
            'framing' => [Rule::in(array_column(SourceChannelFraming::cases(), 'value'))],
            'target' => ['integer', 'min:1', 'max:3600'],
            'tolerance' => ['integer', 'min:1', 'max:3600'],
            'max-clips' => ['integer', 'min:1', 'max:50'],
            'min-score' => ['integer', 'min:1', 'max:10'],
            'max-source-minutes' => ['integer', 'min:1', 'max:1440'],
        ], [
            'url.regex' => 'The url must start with http:// or https:// and contain no whitespace.',
            'project.required' => 'The --project option is required (ContentProject id).',
            'project.integer' => 'The --project option must be a ContentProject id.',
            'project.exists' => 'ContentProject [:input] does not exist.',
            'mode.in' => 'Invalid --mode [:input], expected whole|fixed|highlights.',
            'framing.in' => 'Invalid --framing [:input], expected blur_pad|crop.',
        ], [
            'target' => '--target',
            'tolerance' => '--tolerance',
            'max-clips' => '--max-clips',
            'min-score' => '--min-score',
            'max-source-minutes' => '--max-source-minutes',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $project = ContentProject::findOrFail((int) $this->option('project'));

        try {
            $name = $downloader->channelName($url);
        } catch (Throwable) {
            $name = null;
        }

        $channel = SourceChannel::create([
            'content_project_id' => $project->id,
            'url' => $url,
            'name' => $name,
            'mode' => SourceChannelMode::from($this->option('mode')),
            'target_seconds' => (int) $this->option('target'),
            'tolerance_seconds' => (int) $this->option('tolerance'),
            'max_clips' => (int) $this->option('max-clips'),
            'min_score' => (int) $this->option('min-score'),
            'max_source_minutes' => (int) $this->option('max-source-minutes'),
            'framing' => SourceChannelFraming::from($this->option('framing')),
        ]);

        $this->info("Added source channel #{$channel->id} (mode={$channel->mode->value}).");

        return self::SUCCESS;
    }
}
