<?php

namespace App\Models\Enums;

enum ContentIdeaStatus: string
{
    case New = 'new';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Processing = 'processing';
    case Used = 'used';
}
