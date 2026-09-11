<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'publication_id', 'views', 'likes', 'comments', 'shares', 'saves',
        'watch_time', 'completion_rate', 'followers_gained', 'metadata', 'measured_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'measured_at' => 'datetime',
        ];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }
}
