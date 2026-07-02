<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A durable log of broadcast game events so a client that was disconnected (backgrounded
     * tab, locked phone) can replay what it missed on reconnect. The auto-increment `id` is
     * the client's catch-up cursor (monotonic per insert order). High-volume ephemerals
     * (PlayerMoved) are NOT recorded here — positions re-sync via /state.
     */
    public function up(): void
    {
        Schema::create('game_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('session_id')->constrained('game_sessions')->cascadeOnDelete();
            $table->string('type');
            $table->json('payload')->nullable();
            // Mirror the live broadcast scoping so replay never leaks a player-scoped event.
            $table->string('visibility_scope')->default('everyone'); // everyone | player
            $table->uuid('visibility_player_id')->nullable();
            $table->timestamp('created_at')->nullable();
            // The catch-up query: WHERE session_id = ? AND id > ? ORDER BY id.
            $table->index(['session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_events');
    }
};
