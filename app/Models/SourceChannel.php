<?php

namespace App\Models;

use App\Models\Enums\SourceChannelFraming;
use App\Models\Enums\SourceChannelMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_project_id', 'url', 'name', 'mode', 'target_seconds',
        'tolerance_seconds', 'max_clips', 'min_score', 'max_source_minutes',
        'framing', 'is_active', 'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'mode' => SourceChannelMode::class,
            'framing' => SourceChannelFraming::class,
            'is_active' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    public function contentProject(): BelongsTo
    {
        return $this->belongsTo(ContentProject::class);
    }

    public function sourceVideos(): HasMany
    {
        return $this->hasMany(SourceVideo::class);
    }
}
