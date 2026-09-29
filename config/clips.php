<?php

return [
    'poll_interval_minutes' => (int) env('CLIPS_POLL_INTERVAL_MINUTES', 30),
    'discover_limit' => (int) env('CLIPS_DISCOVER_LIMIT', 5),
    'window_minutes' => (int) env('CLIPS_WINDOW_MINUTES', 90),
    'window_overlap_seconds' => (int) env('CLIPS_WINDOW_OVERLAP_SECONDS', 60),
    'utterance_max_seconds' => (float) env('CLIPS_UTTERANCE_MAX_SECONDS', 15.0),
    'utterance_pause_seconds' => (float) env('CLIPS_UTTERANCE_PAUSE_SECONDS', 0.7),
    'padding_seconds' => (float) env('CLIPS_PADDING_SECONDS', 0.15),
    'subtitle_max_words' => (int) env('CLIPS_SUBTITLE_MAX_WORDS', 6),
    'yt_dlp_binary' => env('YT_DLP_BINARY', 'yt-dlp'),
    'yt_dlp_format' => env('YT_DLP_FORMAT', 'bv*[height<=1080]+ba/b[height<=1080]'),
    'yt_dlp_cookies_file' => env('YT_DLP_COOKIES_FILE'),
    'yt_dlp_timeout' => (int) env('YT_DLP_TIMEOUT', 3600),
];
