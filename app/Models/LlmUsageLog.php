<?php

namespace App\Models;

use App\Models\Enums\LlmUsageLogStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LlmUsageLog extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'content_project_id', 'purpose', 'provider', 'model', 'prompt_tokens',
        'completion_tokens', 'cost', 'duration_ms', 'status', 'error_message', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => LlmUsageLogStatus::class,
            'metadata' => 'array',
        ];
    }

    public function contentProject(): BelongsTo
    {
        return $this->belongsTo(ContentProject::class);
    }
}
