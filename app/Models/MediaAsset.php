<?php

namespace App\Models;

use App\Models\Casts\MetadataCast;
use App\Models\Enums\MediaAssetType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'type', 'provider', 'path', 'mime_type', 'width', 'height',
        'duration', 'metadata', 'hash',
    ];

    protected function casts(): array
    {
        return [
            'type' => MediaAssetType::class,
            'metadata' => MetadataCast::class,
        ];
    }

    public function videoScenes(): HasMany
    {
        return $this->hasMany(VideoScene::class, 'asset_id');
    }
}
