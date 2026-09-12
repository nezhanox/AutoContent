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
        Schema::create('voiceovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->index('video_id');
            $table->string('provider');
            $table->string('voice');
            $table->text('text');
            $table->string('file_path')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->string('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voiceovers');
    }
};
