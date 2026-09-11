<?php

namespace App\Models;

use App\Models\Enums\ContentIdeaStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentIdea extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_project_id', 'title', 'topic', 'source',
        'source_url', 'source_data', 'score', 'status',
    ];

    protected function casts(): array
    {
        return [
            'source_data' => 'array',
            'status' => ContentIdeaStatus::class,
        ];
    }

    public function contentProject(): BelongsTo
    {
        return $this->belongsTo(ContentProject::class);
    }

    public function scripts(): HasMany
    {
        return $this->hasMany(Script::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }
}
