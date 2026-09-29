<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_project_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->string('name')->nullable();
            $table->string('mode')->default('highlights');
            $table->unsignedSmallInteger('target_seconds')->default(60);
            $table->unsignedSmallInteger('tolerance_seconds')->default(15);
            $table->unsignedSmallInteger('max_clips')->default(3);
            $table->unsignedTinyInteger('min_score')->default(6);
            $table->unsignedSmallInteger('max_source_minutes')->default(120);
            $table->string('framing')->default('blur_pad');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_channels');
    }
};
