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
        Schema::create('video_scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('order');
            $table->string('type');
            $table->unsignedInteger('duration');
            $table->text('text');
            $table->string('visual_query')->nullable();
            $table->foreignId('asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->unsignedInteger('start_time')->nullable();
            $table->unsignedInteger('end_time')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_scenes');
    }
};
