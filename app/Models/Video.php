<?php

namespace App\Models;

use App\Models\Enums\VideoStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Video extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_project_id', 'content_idea_id', 'script_id', 'title', 'description',
        'status', 'duration', 'width', 'height', 'file_path', 'thumbnail_path',
        'subtitle_id', 'music_asset_id', 'quality_passed', 'quality_report',
        'metadata', 'error_message', 'failed_stage', 'source_clip_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => VideoStatus::class,
            'metadata' => 'array',
            'quality_passed' => 'boolean',
            'quality_report' => 'array',
        ];
    }

    private const STAGE_LABELS = [
        'scenes' => 'Scenes',
        'voiceover' => 'Voiceover',
        'assets' => 'Assets',
        'subtitles' => 'Subtitles',
        'render' => 'Render',
        'quality_check' => 'Quality check',
    ];

    public function currentStageLabel(): string
    {
        if ($this->status === VideoStatus::Failed) {
            $stage = self::STAGE_LABELS[$this->failed_stage] ?? $this->failed_stage ?? 'unknown';

            return "Failed: {$stage}";
        }

        return match (true) {
            $this->status === VideoStatus::ScriptGenerated => 'Generating voiceover',
            $this->status === VideoStatus::VoiceGenerated => 'Collecting assets',
            $this->status === VideoStatus::AssetsReady && $this->subtitle_id === null => 'Generating subtitles',
            $this->status === VideoStatus::AssetsReady => 'Rendering',
            $this->status === VideoStatus::Rendering => 'Rendering (retry if stalled)',
            $this->status === VideoStatus::Rendered && $this->quality_report === null => 'Checking quality',
            $this->status === VideoStatus::Rendered && $this->quality_passed === false => 'Quality check failed',
            $this->status === VideoStatus::Rendered => 'Done',
            default => $this->status->value,
        };
    }

    public function stageBadgeColor(): string
    {
        return match (true) {
            $this->status === VideoStatus::Failed => 'danger',
            $this->status === VideoStatus::Rendered && $this->quality_passed === false => 'danger',
            $this->status === VideoStatus::Rendered && $this->quality_report !== null => 'success',
            default => 'warning',
        };
    }

    public function contentProject(): BelongsTo
    {
        return $this->belongsTo(ContentProject::class);
    }

    public function contentIdea(): BelongsTo
    {
        return $this->belongsTo(ContentIdea::class);
    }

    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }

    public function sourceClip(): BelongsTo
    {
        return $this->belongsTo(SourceClip::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(VideoScene::class)->orderBy('order');
    }

    public function voiceover(): HasOne
    {
        return $this->hasOne(Voiceover::class);
    }

    public function subtitle(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'subtitle_id');
    }

    public function musicAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'music_asset_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }
}
