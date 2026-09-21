<?php

namespace Database\Seeders;

use App\Models\ContentProject;
use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;
use Illuminate\Database\Seeder;

class StoicismQuotesSeeder extends Seeder
{
    /**
     * Composited split-screen backgrounds (public-domain classical painting on
     * top, a public-domain statue photo silhouette on the bottom) used as the
     * scene visuals for the "Stoicism Quotes" content project.
     *
     * @var array<int, array{file: string, tags: array<int, string>}>
     */
    private const BACKGROUNDS = [
        [
            'file' => 'stoic_bg_1.jpg',
            'tags' => ['stoicism', 'philosophy', 'painting', 'statue', 'socrates', 'classical', 'quote'],
        ],
        [
            'file' => 'stoic_bg_2.jpg',
            'tags' => ['stoicism', 'philosophy', 'painting', 'statue', 'athens', 'classical', 'quote'],
        ],
        [
            'file' => 'stoic_bg_3.jpg',
            'tags' => ['stoicism', 'philosophy', 'painting', 'statue', 'cicero', 'classical', 'quote'],
        ],
        [
            'file' => 'stoic_bg_4.jpg',
            'tags' => ['stoicism', 'philosophy', 'painting', 'statue', 'seneca', 'classical', 'quote'],
        ],
    ];

    public function run(): void
    {
        $project = ContentProject::query()->firstOrCreate(
            ['slug' => 'stoicism-quotes'],
            [
                'name' => 'Stoicism Quotes',
                'description' => 'Short vertical quote-card videos on Stoic philosophy: a classical '
                    .'painting and a statue on a black backdrop, punchy narration, colour-accented captions.',
                'niche' => 'philosophy',
                'language' => 'ru',
                'target_platforms' => ['tiktok'],
                'status' => 'active',
                'settings' => [
                    'tone' => 'punchy, direct second-person address to the viewer ("ты")',
                    'style' => 'short stoic philosophy quote cards, one idea per line, no filler',
                ],
            ],
        );

        foreach (self::BACKGROUNDS as $background) {
            $path = "assets/{$background['file']}";
            $absolutePath = storage_path("app/private/{$path}");

            if (! is_file($absolutePath)) {
                continue;
            }

            MediaAsset::query()->firstOrCreate(
                ['path' => $path],
                [
                    'type' => MediaAssetType::Image,
                    'provider' => 'local',
                    'mime_type' => 'image/jpeg',
                    'width' => 1080,
                    'height' => 1920,
                    'metadata' => ['tags' => $background['tags']],
                    'hash' => hash_file('sha256', $absolutePath),
                ],
            );
        }

        $this->command?->info("Stoicism Quotes project ready (id {$project->id}).");
    }
}
