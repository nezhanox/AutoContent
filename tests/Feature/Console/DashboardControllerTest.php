<?php

namespace Tests\Feature\Console;

use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\PublicationStatus;
use App\Models\Enums\VideoStatus;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_stats_best_videos_and_top_topics(): void
    {
        $this->actingAs(User::factory()->create());

        $project = ContentProject::factory()->create();
        $idea = ContentIdea::factory()->create([
            'content_project_id' => $project->id,
            'topic' => 'ai news',
        ]);
        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'content_idea_id' => $idea->id,
            'status' => VideoStatus::Rendered,
            'title' => 'AI News Roundup',
        ]);
        $account = SocialAccount::factory()->create(['content_project_id' => $project->id]);
        $publication = Publication::factory()->create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'status' => PublicationStatus::Published,
        ]);
        VideoMetric::factory()->create([
            'publication_id' => $publication->id,
            'views' => 1000,
            'likes' => 50,
            'comments' => 5,
            'measured_at' => now(),
        ]);

        $this->get('/console')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('stats.videosGeneratedToday', 1)
                ->where('stats.videosPublished', 1)
                ->where('stats.views', 1000)
                ->has('bestVideos', 1)
                ->where('bestVideos.0.video_title', 'AI News Roundup')
                ->has('topTopics', 1)
                ->where('topTopics.0.topic', 'ai news')
            );
    }
}
