<?php

namespace App\Console\Commands;

use App\Domain\Content\Services\StartVideoGenerationService;
use App\Models\ContentProject;
use Illuminate\Console\Command;
use Throwable;

class GenerateContentIdeaCommand extends Command
{
    protected $signature = 'content-idea:generate {project : ContentProject ID} {topic : Raw topic text}';

    protected $description = 'Generate an approved ContentIdea for a topic and dispatch the full video pipeline (CLI parity with Console "Generate Video").';

    public function handle(StartVideoGenerationService $service): int
    {
        $project = ContentProject::find((int) $this->argument('project'));

        if ($project === null) {
            $this->error("ContentProject [{$this->argument('project')}] not found.");

            return self::FAILURE;
        }

        try {
            $idea = $service->generate($project, $this->argument('topic'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Created ContentIdea #{$idea->id} ({$idea->title}) — GenerateScriptJob dispatched.");

        return self::SUCCESS;
    }
}
