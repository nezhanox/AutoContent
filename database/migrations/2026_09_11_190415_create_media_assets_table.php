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
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('provider');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->json('metadata')->default('{}');
            $table->string('hash');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
