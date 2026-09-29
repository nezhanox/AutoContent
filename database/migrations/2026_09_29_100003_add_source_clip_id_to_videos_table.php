<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->foreignId('source_clip_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('content_idea_id')->nullable()->change();
            $table->unsignedBigInteger('script_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_clip_id');
            $table->unsignedBigInteger('content_idea_id')->nullable(false)->change();
            $table->unsignedBigInteger('script_id')->nullable(false)->change();
        });
    }
};
