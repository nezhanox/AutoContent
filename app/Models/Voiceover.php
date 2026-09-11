<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voiceover extends Model
{
    use HasFactory;

    protected $fillable = [
        'video_id', 'provider', 'voice', 'text', 'file_path', 'duration',
        'metadata', 'status',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
