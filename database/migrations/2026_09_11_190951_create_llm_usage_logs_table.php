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
        Schema::create('llm_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose');
            $table->string('provider');
            $table->string('model');
            $table->unsignedInteger('prompt_tokens');
            $table->unsignedInteger('completion_tokens');
            $table->decimal('cost', 10, 6)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('llm_usage_logs');
    }
};
