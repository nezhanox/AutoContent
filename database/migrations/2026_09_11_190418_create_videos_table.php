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
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_project_id')->constrained()->cascadeOnDelete();
            $table->index('content_project_id');
            $table->foreignId('content_idea_id')->constrained()->cascadeOnDelete();
            $table->index('content_idea_id');
            $table->foreignId('script_id')->constrained()->cascadeOnDelete();
            $table->index('script_id');
            $table->string('title');
            $table->text('description');
            $table->string('status');
            $table->unsignedInteger('duration')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('file_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
