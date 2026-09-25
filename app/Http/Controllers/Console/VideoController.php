<?php

namespace App\Http\Controllers\Console;

use App\Domain\Content\Services\StartVideoGenerationService;
use App\Http\Controllers\Controller;
use App\Jobs\CollectVideoAssetsJob;
use App\Jobs\GenerateScenesJob;
use App\Jobs\GenerateSubtitlesJob;
use App\Jobs\GenerateVoiceoverJob;
use App\Jobs\QualityCheckVideoJob;
use App\Jobs\RenderVideoJob;
use App\Models\ContentProject;
use App\Models\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
            ->limit(100)
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

    public function show(Video $video): Response
    {
        $video->load(['contentProject:id,name', 'contentIdea:id,title', 'script:id,content', 'scenes', 'voiceover', 'subtitle', 'musicAsset']);

        $disk = Storage::disk(config('filesystems.default'));

        return Inertia::render('Videos/Show', [
            'video' => [
                'id' => $video->id,
                'title' => $video->title,
                'description' => $video->description,
                'status' => $video->status->value,
                'stageLabel' => $video->currentStageLabel(),
                'stageColor' => $video->stageBadgeColor(),
                'canRetry' => in_array($video->status, [VideoStatus::Failed, VideoStatus::Rendering], true),
                'channel' => $video->contentProject?->name,
                'channelId' => $video->contentProject?->id,
                'idea' => $video->contentIdea?->title,
                'duration' => $video->duration,
                'width' => $video->width,
                'height' => $video->height,
                'createdAt' => $video->created_at?->toIso8601String(),
                'videoUrl' => $video->file_path ? $disk->temporaryUrl($video->file_path, now()->addMinutes(30)) : null,
                'voiceoverUrl' => $video->voiceover?->file_path ? $disk->temporaryUrl($video->voiceover->file_path, now()->addMinutes(30)) : null,
                'musicAssetLabel' => $video->musicAsset?->path,
                'scriptText' => $video->script?->content,
                'subtitlesText' => $video->subtitle?->path ? $disk->get($video->subtitle->path) : null,
                'scenes' => $video->scenes->map(fn ($scene) => [
                    'order' => $scene->order,
                    'type' => $scene->type->value,
                    'duration' => $scene->duration,
                    'text' => $scene->text,
                    'visualQuery' => $scene->visual_query,
                ])->all(),
                'qualityPassed' => $video->quality_passed,
                'qualityReport' => $video->quality_report,
                'errorMessage' => $video->error_message,
                'failedStage' => $video->failed_stage,
            ],
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'content_project_id' => ['required', 'integer', 'exists:content_projects,id'],
            'topic' => ['required', 'string', 'max:255'],
        ]);

        try {
            app(StartVideoGenerationService::class)->generate(
                ContentProject::findOrFail($data['content_project_id']),
                $data['topic'],
            );
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
