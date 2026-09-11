<?php

namespace App\Models\Enums;

enum VideoSceneType: string
{
    case Hook = 'hook';
    case Broll = 'broll';
    case ScreenRecording = 'screen_recording';
    case Image = 'image';
    case Screenshot = 'screenshot';
    case Text = 'text';
    case GeneratedVideo = 'generated_video';
    case Transition = 'transition';
    case Cta = 'cta';
}
