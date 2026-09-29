<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceClip extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_video_id', 'start', 'end', 'title', 'hook', 'score', 'reason', 'video_id',
    ];

    protected function casts(): array
    {
        return [
            'start' => 'float',
            'end' => 'float',
        ];
    }

    public function sourceVideo(): BelongsTo
    {
        return $this->belongsTo(SourceVideo::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
