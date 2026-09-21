<?php

return [
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),

    // Timeout for a SINGLE ffmpeg/ffprobe invocation (render() makes several
    // per render). Intentionally much smaller than RenderVideoJob::$timeout
    // (900s) — a single hung call must never be able to exhaust the job's
    // whole budget by itself.
    'timeout' => (int) env('RENDER_TIMEOUT', 180),

    'resolution' => [
        'width' => (int) env('RENDER_WIDTH', 1080),
        'height' => (int) env('RENDER_HEIGHT', 1920),
    ],

    'fps' => (int) env('RENDER_FPS', 30),

    'subtitles' => [
        'font' => env('RENDER_SUBTITLE_FONT', 'DejaVu Sans'),
        'font_size' => (int) env('RENDER_SUBTITLE_FONT_SIZE', 64),
        'position' => env('RENDER_SUBTITLE_POSITION', 'bottom'),
        'margin_v' => (int) env('RENDER_SUBTITLE_MARGIN_V', 120),
        'margin_h' => (int) env('RENDER_SUBTITLE_MARGIN_H', 60),
        'primary_colour' => env('RENDER_SUBTITLE_COLOR', '&H00FFFFFF'),
        'outline_colour' => env('RENDER_SUBTITLE_OUTLINE_COLOR', '&H00000000'),
        // Per-word colour overrides applied by CaptionHighlighter based on the
        // word's stem (e.g. "сил-" => positive/yellow, "слаб-" => negative/red).
        'accent_colour_positive' => env('RENDER_SUBTITLE_ACCENT_POSITIVE', '&H0000FFFF'),
        'accent_colour_negative' => env('RENDER_SUBTITLE_ACCENT_NEGATIVE', '&H000000FF'),
    ],

    'audio' => [
        'voice_volume' => (float) env('RENDER_VOICE_VOLUME', 1.0),
        'music_volume' => (float) env('RENDER_MUSIC_VOLUME', 0.15),
    ],

    'transition' => [
        'type' => env('RENDER_TRANSITION_TYPE', 'fade'),
        'duration' => (float) env('RENDER_TRANSITION_DURATION', 0.5),
    ],

    'quality_check' => [
        'duration_tolerance' => (float) env('RENDER_QUALITY_DURATION_TOLERANCE', 2.0),
    ],
];
