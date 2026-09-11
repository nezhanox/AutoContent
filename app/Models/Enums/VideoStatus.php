<?php

namespace App\Models\Enums;

enum VideoStatus: string
{
    case Draft = 'draft';
    case ScriptGenerated = 'script_generated';
    case VoiceGenerated = 'voice_generated';
    case AssetsReady = 'assets_ready';
    case Rendering = 'rendering';
    case Rendered = 'rendered';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
}
