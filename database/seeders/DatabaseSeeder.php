<?php

namespace Database\Seeders;

use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\LlmUsageLog;
use App\Models\MediaAsset;
use App\Models\Publication;
use App\Models\Script;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoMetric;
use App\Models\VideoScene;
use App\Models\Voiceover;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@autocontent.test',
            'password' => bcrypt('password'),
        ]);

        MediaAsset::factory()->count(10)->create();

        ContentProject::factory()
            ->count(3)
            ->has(
                ContentIdea::factory()
                    ->count(3)
                    ->has(Script::factory()->count(1))
            )
            ->has(SocialAccount::factory()->count(2))
            ->create()
            ->each(function (ContentProject $project) {
                // Re-load explicitly: factory `has()` does not reliably leave these
                // relations set on the in-memory model after ->create().
                $project->load(['contentIdeas.scripts', 'socialAccounts']);

                $idea = $project->contentIdeas->first();
                $script = $idea?->scripts->first();

                $video = Video::factory()->create([
                    'content_project_id' => $project->id,
                    'content_idea_id' => $idea->id,
                    'script_id' => $script->id,
                ]);

                VideoScene::factory()->count(4)->create(['video_id' => $video->id]);
                Voiceover::factory()->create(['video_id' => $video->id]);

                $account = $project->socialAccounts->first();
                if ($account) {
                    $publication = Publication::factory()->create([
                        'video_id' => $video->id,
                        'social_account_id' => $account->id,
                    ]);

                    VideoMetric::factory()->create(['publication_id' => $publication->id]);
                }

                LlmUsageLog::factory()->count(2)->create(['content_project_id' => $project->id]);
            });

        $this->call(StoicismQuotesSeeder::class);
    }
}
