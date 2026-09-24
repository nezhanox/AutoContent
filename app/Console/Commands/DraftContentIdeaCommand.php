<?php

namespace App\Console\Commands;

use App\Domain\Content\Exceptions\ScriptGenerationFailedException;
use App\Domain\Content\Services\GenerateScriptService;
use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Video\Exceptions\SceneGenerationFailedException;
use App\Domain\Video\Services\GenerateScenesService;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Script;
use Illuminate\Console\Command;

class DraftContentIdeaCommand extends Command
{
    protected $signature = 'content-idea:draft {project : ContentProject ID} {title : Idea title to preview} {topic : Idea topic/description to preview}';

    protected $description = 'Preview a script+scenes generation without persisting anything (no DB writes beyond reading the project).';

    public function handle(LlmManagerInterface $llmManager, GenerateScriptService $scriptService, GenerateScenesService $scenesService): int
    {
        $project = ContentProject::find((int) $this->argument('project'));

        if ($project === null) {
            $this->error("ContentProject [{$this->argument('project')}] not found.");

            return self::FAILURE;
        }

        $idea = (new ContentIdea([
            'content_project_id' => $project->id,
            'title' => $this->argument('title'),
            'topic' => $this->argument('topic'),
        ]))->setRelation('contentProject', $project);

        $target = $llmManager->resolve($project, 'script');

        try {
            $scriptData = $scriptService->generate($idea, $target);
        } catch (ScriptGenerationFailedException $exception) {
            $this->error("Script generation failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $script = (new Script([
            'content' => $scriptData['script'],
            'metadata' => ['title' => $scriptData['title']],
        ]))->setRelation('contentIdea', $idea);

        try {
            $scenes = $scenesService->generate($script, $target);
        } catch (SceneGenerationFailedException $exception) {
            $this->error("Scene generation failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->line(json_encode([
            'script' => $scriptData,
            'scenes' => $scenes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
