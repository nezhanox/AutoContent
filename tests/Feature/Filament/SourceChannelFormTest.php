<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\SourceChannels\Pages\CreateSourceChannel;
use App\Models\ContentProject;
use App\Models\SourceChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SourceChannelFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function valid(array $overrides = []): array
    {
        return array_merge([
            'content_project_id' => ContentProject::factory()->create()->id,
            'url' => 'https://www.youtube.com/@example',
            'mode' => 'highlights',
            'target_seconds' => 45,
            'tolerance_seconds' => 10,
            'max_clips' => 3,
            'min_score' => 7,
            'max_source_minutes' => 120,
            'framing' => 'blur_pad',
            'is_active' => true,
        ], $overrides);
    }

    public function test_a_source_channel_can_be_created(): void
    {
        $data = $this->valid();

        Livewire::test(CreateSourceChannel::class)
            ->fillForm($data)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('source_channels', [
            'content_project_id' => $data['content_project_id'],
            'url' => 'https://www.youtube.com/@example',
            'mode' => 'highlights',
            'min_score' => 7,
        ]);
    }

    public function test_an_empty_url_is_rejected(): void
    {
        Livewire::test(CreateSourceChannel::class)
            ->fillForm($this->valid(['url' => '']))
            ->call('create')
            ->assertHasFormErrors(['url' => 'required']);

        $this->assertSame(0, SourceChannel::count());
    }

    public function test_an_option_like_url_is_rejected(): void
    {
        Livewire::test(CreateSourceChannel::class)
            ->fillForm($this->valid(['url' => '--exec=foo']))
            ->call('create')
            ->assertHasFormErrors(['url']);

        $this->assertSame(0, SourceChannel::count());
    }

    public function test_a_url_with_whitespace_is_rejected(): void
    {
        Livewire::test(CreateSourceChannel::class)
            ->fillForm($this->valid(['url' => 'https://youtube.com/@a --exec=foo']))
            ->call('create')
            ->assertHasFormErrors(['url']);
    }

    public function test_out_of_range_values_are_rejected(): void
    {
        Livewire::test(CreateSourceChannel::class)
            ->fillForm($this->valid(['min_score' => 11, 'max_clips' => 51, 'target_seconds' => 0]))
            ->call('create')
            ->assertHasFormErrors(['min_score', 'max_clips', 'target_seconds']);
    }

    public function test_whole_mode_hides_clip_fields(): void
    {
        Livewire::test(CreateSourceChannel::class)
            ->fillForm(['mode' => 'whole'])
            ->assertFormFieldIsHidden('min_score')
            ->assertFormFieldIsHidden('max_clips')
            ->fillForm(['mode' => 'fixed'])
            ->assertFormFieldIsVisible('min_score');
    }

    public function test_a_whole_mode_channel_can_be_created(): void
    {
        Livewire::test(CreateSourceChannel::class)
            ->fillForm($this->valid(['mode' => 'whole']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('source_channels', ['mode' => 'whole']);
    }
}
