<?php

namespace App\Models\Enums;

enum SourceChannelMode: string
{
    case Whole = 'whole';
    case Fixed = 'fixed';
    case Highlights = 'highlights';
}
