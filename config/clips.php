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
    // Burned-in caption style for YouTube clips; merged over config('render.subtitles').
    // Lower third: bottom-anchored, MarginV = distance from the frame bottom to the
    // text's bottom edge (500 px of 1920 puts the block in the blurred band under the picture).
    'subtitles' => [
        'font' => env('CLIPS_SUBTITLE_FONT', 'Poppins ExtraBold'),
        'font_size' => (int) env('CLIPS_SUBTITLE_FONT_SIZE', 76),
        'bold' => false,
        'position' => 'bottom',
        'margin_v' => (int) env('CLIPS_SUBTITLE_MARGIN_V', 500),
        'margin_h' => (int) env('CLIPS_SUBTITLE_MARGIN_H', 90),
        'outline_width' => (int) env('CLIPS_SUBTITLE_OUTLINE_WIDTH', 4),
        'shadow_width' => (int) env('CLIPS_SUBTITLE_SHADOW_WIDTH', 2),
    ],
    'fonts_dir' => env('CLIPS_FONTS_DIR', resource_path('fonts')),
    'yt_dlp_binary' => env('YT_DLP_BINARY', 'yt-dlp'),
    'yt_dlp_format' => env('YT_DLP_FORMAT', 'bv*[height<=1080]+ba/b[height<=1080]'),
    'yt_dlp_cookies_file' => env('YT_DLP_COOKIES_FILE'),
    'max_output_tokens' => (int) env('CLIPS_MAX_OUTPUT_TOKENS', 8192),
    'yt_dlp_timeout' => (int) env('YT_DLP_TIMEOUT', 3600),
    'yt_dlp_list_timeout' => (int) env('YT_DLP_LIST_TIMEOUT', 120),
];
