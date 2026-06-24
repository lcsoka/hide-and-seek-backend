<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('game_sessions')->cascadeOnDelete();
            $table->foreignUuid('player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->string('type')->index();
            $table->json('payload')->nullable();
            // Append-only: only created_at (model sets const UPDATED_AT = null).
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_logs');
    }
};
