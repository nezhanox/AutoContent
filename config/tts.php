<?php

return [
    'default_voice' => env('ELEVENLABS_DEFAULT_VOICE'),
    'providers' => [
        'elevenlabs' => [
            'api_key' => env('ELEVENLABS_API_KEY'),
            'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io/v1'),
            'model_id' => env('ELEVENLABS_MODEL_ID', 'eleven_multilingual_v2'),
        ],
    ],
];
