<?php

namespace App\Http\Controllers\Console;

use App\Domain\Content\Services\GenerateContentIdeaService;
use App\Http\Controllers\Controller;
use App\Jobs\CollectVideoAssetsJob;
use App\Jobs\GenerateScenesJob;
use App\Jobs\GenerateScriptJob;
use App\Jobs\GenerateSubtitlesJob;
use App\Jobs\GenerateVoiceoverJob;
use App\Jobs\QualityCheckVideoJob;
use App\Jobs\RenderVideoJob;
use App\Models\ContentProject;
use App\Models\Enums\ContentIdeaStatus;
use App\Models\Enums\VideoStatus;
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
                'canRetry' => in_array($video->status, [VideoStatus::Failed, VideoStatus::Rendering], true),
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

    public function retry(Video $video): RedirectResponse
    {
        $stage = $video->status === VideoStatus::Rendering
            ? 'render'
            : $video->failed_stage;

        if ($stage === null) {
            return back()->withErrors(['video' => 'Cannot retry — no failed stage recorded.']);
        }

        $video->update([
            'status' => match ($stage) {
                'scenes', 'voiceover' => VideoStatus::ScriptGenerated,
                'assets' => VideoStatus::VoiceGenerated,
                'subtitles', 'render' => VideoStatus::AssetsReady,
                'quality_check' => VideoStatus::Rendered,
                default => $video->status,
            },
            'failed_stage' => null,
            'error_message' => null,
        ]);

        match ($stage) {
            'scenes' => GenerateScenesJob::dispatch($video->script_id),
            'voiceover' => GenerateVoiceoverJob::dispatch($video->id),
            'assets' => CollectVideoAssetsJob::dispatch($video->id),
            'subtitles' => GenerateSubtitlesJob::dispatch($video->id),
            'render' => RenderVideoJob::dispatch($video->id),
            'quality_check' => QualityCheckVideoJob::dispatch($video->id),
            default => null,
        };

        return back();
    }
}
