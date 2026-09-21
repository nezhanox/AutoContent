<?php

return [
    // Order in which AssetServiceProvider tries providers before giving up.
    'chain' => ['pixabay', 'pexels', 'local'],

    'providers' => [
        'pixabay' => [
            'api_key' => env('PIXABAY_API_KEY'),
            'base_url' => env('PIXABAY_BASE_URL', 'https://pixabay.com/api'),
        ],
        'pexels' => [
            'api_key' => env('PEXELS_API_KEY'),
            'base_url' => env('PEXELS_BASE_URL', 'https://api.pexels.com'),
        ],
    ],
];
