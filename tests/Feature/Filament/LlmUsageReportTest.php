<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\LlmUsageReport;
use App\Models\Enums\LlmUsageLogStatus;
use App\Models\LlmUsageLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LlmUsageReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_groups_usage_by_provider_model_and_purpose(): void
    {
        $this->actingAs(User::factory()->create());

        LlmUsageLog::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'purpose' => 'idea',
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'cost' => 0.01,
            'status' => LlmUsageLogStatus::Success,
        ]);

        LlmUsageLog::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'purpose' => 'idea',
            'prompt_tokens' => 200,
            'completion_tokens' => 100,
            'cost' => 0.02,
            'status' => LlmUsageLogStatus::Failed,
        ]);

        LlmUsageLog::factory()->create([
            'provider' => 'anthropic',
            'model' => 'claude-opus-4',
            'purpose' => 'script',
            'prompt_tokens' => 500,
            'completion_tokens' => 300,
            'cost' => 1.5,
            'status' => LlmUsageLogStatus::Success,
        ]);

        Livewire::test(LlmUsageReport::class)
            ->assertSeeHtmlInOrder(['anthropic', 'openai'])
            ->assertSee('300')
            ->assertSee('50%');
    }
}
