<?php

namespace App\Models\Enums;

enum VoiceoverStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
