<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ContentIdea;
use App\Models\Enums\PublicationStatus;
use App\Models\Enums\VideoStatus;
use App\Models\Publication;
use App\Models\Video;
use App\Models\VideoMetric;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $latestMetrics = VideoMetric::latestPerPublication()->get();

        $stats = [
            'videosGeneratedToday' => Video::where('status', VideoStatus::Rendered)
                ->whereDate('updated_at', today())
                ->count(),
            'videosPublished' => Publication::where('status', PublicationStatus::Published)->count(),
            'failedJobsToday' => $request->user()
                ->notifications()
                ->where('type', PipelineJobFailedNotification::class)
                ->whereDate('created_at', today())
                ->count(),
            'views' => (int) $latestMetrics->sum('views'),
            'likes' => (int) $latestMetrics->sum('likes'),
            'comments' => (int) $latestMetrics->sum('comments'),
        ];

        $bestVideos = Publication::query()
            ->joinSub(VideoMetric::latestPerPublication(), 'latest_metrics', 'latest_metrics.publication_id', '=', 'publications.id')
            ->join('videos', 'videos.id', '=', 'publications.video_id')
            ->join('social_accounts', 'social_accounts.id', '=', 'publications.social_account_id')
            ->orderByDesc('latest_metrics.views')
            ->limit(5)
            ->get([
                'publications.id',
                'videos.title as video_title',
                'social_accounts.platform',
                'latest_metrics.views',
                'latest_metrics.likes',
                'latest_metrics.comments',
            ]);

        $topTopics = ContentIdea::query()
            ->join('videos', 'videos.content_idea_id', '=', 'content_ideas.id')
            ->join('publications', 'publications.video_id', '=', 'videos.id')
            ->joinSub(VideoMetric::latestPerPublication(), 'latest_metrics', 'latest_metrics.publication_id', '=', 'publications.id')
            ->selectRaw('content_ideas.topic, SUM(latest_metrics.views) as total_views')
            ->groupBy('content_ideas.topic')
            ->orderByDesc('total_views')
            ->limit(5)
            ->get();

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'bestVideos' => $bestVideos,
            'topTopics' => $topTopics,
        ]);
    }
}
