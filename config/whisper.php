<?php

return [
    'python_binary' => env('WHISPER_PYTHON_BINARY', 'python3'),
    'script_path' => env('WHISPER_SCRIPT_PATH', base_path('scripts/whisper_transcribe.py')),
    'model' => env('WHISPER_MODEL', 'base'),
    'timeout' => (int) env('WHISPER_TIMEOUT', 600),
];
