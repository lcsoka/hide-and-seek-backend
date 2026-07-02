<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A throttled time-series of player positions, so finished games can be replayed with real
 * movement (players only store their latest position). Written ~every few seconds per player and
 * cascaded away with the session, so it stays bounded and is pruned along with the game.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_positions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('session_id')->constrained('game_sessions')->cascadeOnDelete();
            $table->foreignUuid('player_id')->constrained('players')->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->timestamp('recorded_at');
            $table->index(['session_id', 'recorded_at']);
            $table->index(['player_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_positions');
    }
};
