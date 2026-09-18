<?php

namespace Tests\Feature\Models;

use App\Models\Publication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_caption_and_hashtags(): void
    {
        $publication = Publication::factory()->create([
            'caption' => 'Check this out!',
            'hashtags' => ['fyp', 'viral'],
        ]);

        $fresh = $publication->fresh();
        $this->assertSame('Check this out!', $fresh->caption);
        $this->assertSame(['fyp', 'viral'], $fresh->hashtags);
    }
}
