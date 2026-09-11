<?php

namespace App\Models;

use App\Models\Enums\VideoSceneType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoScene extends Model
{
    use HasFactory;

    protected $fillable = [
        'video_id', 'order', 'type', 'duration', 'text', 'visual_query',
        'asset_id', 'start_time', 'end_time', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type' => VideoSceneType::class,
            'metadata' => 'array',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'asset_id');
    }
}
