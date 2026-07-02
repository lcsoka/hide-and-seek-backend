<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foreign keys don't imply an index in SQLite, so the per-session child fetches
     * (loaded on every /state build) and the prune query were full table scans.
     */
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->index('session_id');
        });
        Schema::table('teams', function (Blueprint $table) {
            $table->index('session_id');
        });
        Schema::table('action_logs', function (Blueprint $table) {
            $table->index('session_id');
        });
        Schema::table('game_sessions', function (Blueprint $table) {
            // Prune scans idle open/running by activity, and deletes terminal rows by ended_at.
            $table->index('last_activity_at');
            $table->index(['status', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropIndex(['session_id']);
        });
        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex(['session_id']);
        });
        Schema::table('action_logs', function (Blueprint $table) {
            $table->dropIndex(['session_id']);
        });
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->dropIndex(['last_activity_at']);
            $table->dropIndex(['status', 'ended_at']);
        });
    }
};
