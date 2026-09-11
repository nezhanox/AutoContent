<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'description', 'niche', 'language',
        'target_platforms', 'status', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'target_platforms' => 'array',
            'settings' => 'array',
        ];
    }

    public function contentIdeas(): HasMany
    {
        return $this->hasMany(ContentIdea::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }
}
