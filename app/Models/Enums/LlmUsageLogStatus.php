<?php

namespace App\Models\Enums;

enum LlmUsageLogStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
}
