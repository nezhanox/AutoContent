<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('voiceovers', function (Blueprint $table) {
            $table->dropIndex(['video_id']);
            $table->unique('video_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('voiceovers', function (Blueprint $table) {
            $table->dropUnique(['video_id']);
            $table->index('video_id');
        });
    }
};
