<?php

namespace App\Models;

use App\Models\Enums\SourceVideoStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceVideo extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_channel_id', 'youtube_id', 'title', 'duration', 'file_path',
        'transcript', 'status', 'failed_stage', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'duration' => 'float',
            'transcript' => 'array',
            'status' => SourceVideoStatus::class,
        ];
    }

    public function sourceChannel(): BelongsTo
    {
        return $this->belongsTo(SourceChannel::class);
    }

    public function clips(): HasMany
    {
        return $this->hasMany(SourceClip::class);
    }
}
