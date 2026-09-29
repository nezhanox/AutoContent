<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_channel_id')->constrained()->cascadeOnDelete();
            $table->string('youtube_id')->unique();
            $table->string('title');
            $table->double('duration')->default(0);
            $table->string('file_path')->nullable();
            $table->json('transcript')->nullable();
            $table->string('status')->default('discovered');
            $table->string('failed_stage')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_videos');
    }
};
