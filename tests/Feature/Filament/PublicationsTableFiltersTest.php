<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Publications\Pages\ListPublications;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PublicationsTableFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_filter_narrows_the_table_to_matching_publications(): void
    {
        $this->actingAs(User::factory()->create());

        $scheduled = Publication::factory()->create(['status' => PublicationStatus::Scheduled]);
        Publication::factory()->create(['status' => PublicationStatus::Draft]);

        Livewire::test(ListPublications::class)
            ->filterTable('status', PublicationStatus::Scheduled->value)
            ->assertCanSeeTableRecords([$scheduled])
            ->assertCountTableRecords(1);
    }

    public function test_scheduled_at_filter_narrows_the_table_to_the_given_range(): void
    {
        $this->actingAs(User::factory()->create());

        $inRange = Publication::factory()->create(['scheduled_at' => now()->addDays(2)]);
        Publication::factory()->create(['scheduled_at' => now()->addDays(10)]);

        Livewire::test(ListPublications::class)
            ->filterTable('scheduled_at', [
                'scheduled_from' => now()->format('Y-m-d'),
                'scheduled_until' => now()->addDays(5)->format('Y-m-d'),
            ])
            ->assertCanSeeTableRecords([$inRange])
            ->assertCountTableRecords(1);
    }
}
