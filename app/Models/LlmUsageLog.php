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
            // Postgres hands back `decimal` columns as untyped PHP strings via PDO;
            // the cast pins them to the column's own precision.
            'cost' => 'decimal:6',
            'metadata' => 'array',
        ];
    }

    public function contentProject(): BelongsTo
    {
        return $this->belongsTo(ContentProject::class);
    }
}
