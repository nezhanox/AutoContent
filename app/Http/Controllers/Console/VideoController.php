<?php

namespace App\Http\Controllers\Console;

use App\Domain\Content\Services\GenerateContentIdeaService;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateScriptJob;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use App\Models\Video;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class VideoController extends Controller
{
    public function index(): Response
    {
        $videos = Video::query()
            ->with(['contentProject:id,name', 'contentIdea:id,title'])
            ->latest('id')
            ->get()
            ->map(fn (Video $video) => [
                'id' => $video->id,
                'channel' => $video->contentProject?->name,
                'idea' => $video->contentIdea?->title,
                'title' => $video->title,
                'status' => $video->status->value,
                'stageLabel' => $video->currentStageLabel(),
                'stageColor' => $video->stageBadgeColor(),
                'createdAt' => $video->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Videos/Index', [
            'videos' => $videos,
            'channels' => ContentProject::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'content_project_id' => ['required', 'integer', 'exists:content_projects,id'],
            'topic' => ['required', 'string', 'max:255'],
        ]);

        try {
            $idea = app(GenerateContentIdeaService::class)->generate(
                ContentProject::findOrFail($data['content_project_id']),
                $data['topic'],
            );

            $idea->update(['status' => ContentIdeaStatus::Approved]);

            GenerateScriptJob::dispatch($idea->id);
        } catch (Throwable $exception) {
            return back()->withErrors(['topic' => $exception->getMessage()]);
        }

        return back();
    }
}
