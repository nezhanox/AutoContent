<?php

namespace App\Models\Enums;

enum SourceVideoStatus: string
{
    case Discovered = 'discovered';
    case Downloading = 'downloading';
    case Downloaded = 'downloaded';
    case Transcribed = 'transcribed';
    case ClipsSelected = 'clips_selected';
    case ClipsCreated = 'clips_created';
    case Skipped = 'skipped';
    case NoClips = 'no_clips';
    case Failed = 'failed';
}
