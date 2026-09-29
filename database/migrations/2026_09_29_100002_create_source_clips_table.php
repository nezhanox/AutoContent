<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_clips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_video_id')->constrained()->cascadeOnDelete();
            $table->double('start');
            $table->double('end');
            $table->string('title');
            $table->text('hook')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('video_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_clips');
    }
};
