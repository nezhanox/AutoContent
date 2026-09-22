<?php

return [
    // Order in which AssetServiceProvider tries providers before giving up.
    // wikimedia goes first: it's the only source with real classical art
    // (paintings, statues, sculptures) for historical/philosophical scenes,
    // and returns no hits for queries it has nothing relevant for, so the
    // chain falls through to the commercial stock providers as before.
    'chain' => ['wikimedia', 'pixabay', 'pexels', 'local'],

    'providers' => [
        'pixabay' => [
            'api_key' => env('PIXABAY_API_KEY'),
            'base_url' => env('PIXABAY_BASE_URL', 'https://pixabay.com/api'),
        ],
        'pexels' => [
            'api_key' => env('PEXELS_API_KEY'),
            'base_url' => env('PEXELS_BASE_URL', 'https://api.pexels.com'),
        ],
        'wikimedia' => [
            'base_url' => env('WIKIMEDIA_BASE_URL', 'https://commons.wikimedia.org'),
        ],
    ],
];
