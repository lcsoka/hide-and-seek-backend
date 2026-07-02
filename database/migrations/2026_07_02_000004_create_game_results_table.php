<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A durable per-user, per-game result written when a game finishes — so a registered user's
     * history/stats survive even after the session itself is pruned (retention). Cascades away
     * with the user (so guest stats are ephemeral); the session_id nulls out when the session is
     * pruned, keeping the result row.
     */
    public function up(): void
    {
        Schema::create('game_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('game_sessions')->nullOnDelete();
            $table->string('display_name');
            $table->unsignedInteger('hide_time_s')->default(0); // total banked hiding time this game
            $table->boolean('won')->default(false);             // finished top of the standings
            $table->unsignedSmallInteger('players_count')->default(0);
            $table->timestamp('played_at')->nullable();
            $table->index(['user_id', 'played_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_results');
    }
};
