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
        Schema::create('scripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_idea_id')->constrained()->cascadeOnDelete();
            $table->index('content_idea_id');
            $table->string('provider');
            $table->string('model');
            $table->string('prompt_version');
            $table->longText('content')->nullable();
            $table->text('hook')->nullable();
            $table->unsignedInteger('estimated_duration')->nullable();
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
        Schema::dropIfExists('scripts');
    }
};
