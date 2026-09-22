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

    // Backdrop for `text`-type scenes (pure caption cards with no background
    // media) — any ffmpeg lavfi `color` source name or hex. Deliberately not
    // pure black: FfprobeVideoQualityChecker's blackdetect (pixel_black_th
    // 0.10, i.e. channel value <= ~25) fails a render with >=1s of true black,
    // so a text-only scene of any normal duration would always fail quality
    // review. 0x262629 (channel value 38-41) reads as near-black on screen
    // while staying safely above that threshold.
    'text_scene_background' => env('RENDER_TEXT_SCENE_BACKGROUND', '0x262629'),

    'subtitles' => [
        'font' => env('RENDER_SUBTITLE_FONT', 'Oswald'),
        'font_size' => (int) env('RENDER_SUBTITLE_FONT_SIZE', 64),
        // 'middle' keeps captions clear of the top/bottom edges, where a
        // scene's own photo content (faces, horizon lines) most often sits.
        'position' => env('RENDER_SUBTITLE_POSITION', 'middle'),
        'margin_v' => (int) env('RENDER_SUBTITLE_MARGIN_V', 120),
        'margin_h' => (int) env('RENDER_SUBTITLE_MARGIN_H', 60),
        'primary_colour' => env('RENDER_SUBTITLE_COLOR', '&H00FFFFFF'),
        'outline_colour' => env('RENDER_SUBTITLE_OUTLINE_COLOR', '&H00000000'),
        // Explicit outline/shadow width so a light word (e.g. the yellow/red
        // accent colours below) stays readable over a light photo background
        // regardless of the renderer's own default border thickness.
        'outline_width' => (float) env('RENDER_SUBTITLE_OUTLINE_WIDTH', 3.5),
        'shadow_width' => (float) env('RENDER_SUBTITLE_SHADOW_WIDTH', 1.5),
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
