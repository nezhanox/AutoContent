<?php

namespace App\Domain\Content\Services;

use App\Jobs\GenerateScriptJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;

final class StartVideoGenerationService
{
    public function __construct(private readonly GenerateContentIdeaService $ideaService) {}

    public function generate(ContentProject $project, string $topic): ContentIdea
    {
        $idea = $this->ideaService->generate($project, $topic);

        $idea->update(['status' => ContentIdeaStatus::Approved]);

        GenerateScriptJob::dispatch($idea->id);

        return $idea;
    }
}
