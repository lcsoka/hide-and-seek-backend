<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record how good each GPS fix was (the browser's `coords.accuracy`, in metres).
 *
 * Without it every reading looks equally trustworthy, but a phone indoors or in a street canyon
 * falls back to a wifi/cell-tower fix that can be hundreds of metres off. Those readings silently
 * corrupt the game: they move the hider's committed spot (the ground truth every question is
 * judged against), shift the asker reference point a cut is drawn from, and can trip the endgame
 * proximity trigger from streets away. Storing the accuracy lets each decision apply its own
 * tolerance instead of trusting every fix blindly.
 *
 * Nullable on purpose: rows written before this (and the dev harness, which places players by
 * hand) carry null and stay trusted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->float('last_accuracy_m')->nullable()->after('last_lng');
        });

        Schema::table('player_positions', function (Blueprint $table) {
            $table->float('accuracy_m')->nullable()->after('lng');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('last_accuracy_m');
        });

        Schema::table('player_positions', function (Blueprint $table) {
            $table->dropColumn('accuracy_m');
        });
    }
};
