<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            // Last player activity (action or location ping) — drives abandonment.
            $table->timestamp('last_activity_at')->nullable()->after('status');
            // When the session reached a terminal status (finished/abandoned).
            $table->timestamp('ended_at')->nullable()->after('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->dropColumn(['last_activity_at', 'ended_at']);
        });
    }
};
