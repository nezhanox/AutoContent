<?php

namespace App\Models\Enums;

enum MediaAssetType: string
{
    case Video = 'video';
    case Image = 'image';
    case Audio = 'audio';
    case Subtitle = 'subtitle';
    case ScreenRecording = 'screen_recording';
    case Thumbnail = 'thumbnail';
}
