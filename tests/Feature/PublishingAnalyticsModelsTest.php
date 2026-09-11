<?php

namespace Tests\Feature;

use App\Models\ContentProject;
use App\Models\LlmUsageLog;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\VideoMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishingAnalyticsModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_social_account_tokens_are_encrypted_at_rest(): void
    {
        $account = SocialAccount::factory()->create(['access_token' => 'plaintext-secret-token']);

        $raw = \DB::table('social_accounts')->where('id', $account->id)->value('access_token');

        $this->assertNotSame('plaintext-secret-token', $raw);
        $this->assertSame('plaintext-secret-token', $account->fresh()->access_token);
    }

    public function test_social_account_tokens_are_hidden_from_serialization(): void
    {
        $account = SocialAccount::factory()->create();

        $this->assertArrayNotHasKey('access_token', $account->toArray());
        $this->assertArrayNotHasKey('refresh_token', $account->toArray());
    }

    public function test_publication_belongs_to_video_and_social_account(): void
    {
        $publication = Publication::factory()->create();

        $this->assertNotNull($publication->video);
        $this->assertNotNull($publication->socialAccount);
    }

    public function test_video_metric_belongs_to_publication_and_deletes_with_it(): void
    {
        $publication = Publication::factory()->create();
        $metric = VideoMetric::factory()->create(['publication_id' => $publication->id]);

        $publication->delete();

        $this->assertDatabaseMissing('video_metrics', ['id' => $metric->id]);
    }

    public function test_llm_usage_log_survives_content_project_deletion(): void
    {
        $log = LlmUsageLog::factory()->create();
        $projectId = $log->content_project_id;

        ContentProject::find($projectId)->delete();

        $this->assertDatabaseHas('llm_usage_logs', ['id' => $log->id, 'content_project_id' => null]);
    }
}
