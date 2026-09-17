<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->foreignId('music_asset_id')->nullable()->after('subtitle_id')
                ->constrained('media_assets')->nullOnDelete();
            $table->boolean('quality_passed')->nullable()->after('metadata');
            $table->jsonb('quality_report')->nullable()->after('quality_passed');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropForeign(['music_asset_id']);
            $table->dropColumn(['music_asset_id', 'quality_passed', 'quality_report']);
        });
    }
};
