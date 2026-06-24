<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('join_code')->unique();
            $table->string('game_mode')->index();
            $table->string('state')->default('lobby');
            $table->json('state_data')->nullable();   // mode-owned blob
            $table->json('config')->nullable();        // resolved GameModeConfig
            // Soft reference to players.id; intentionally unconstrained to avoid the
            // game_sessions <-> players circular dependency.
            $table->uuid('host_player_id')->nullable();
            $table->string('status')->default('open')->index(); // open|running|finished
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_sessions');
    }
};
