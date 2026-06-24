<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type')->index();                 // FeedbackType: suggestion|bug
            $table->string('subject')->nullable();
            $table->text('message');
            // Optional context — who/where. No user_id yet (accounts come later).
            $table->foreignUuid('session_id')->nullable()->constrained('game_sessions')->nullOnDelete();
            $table->foreignUuid('player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->string('contact')->nullable();           // email/handle for follow-up
            $table->json('context')->nullable();             // app version, url, state snapshot
            $table->string('status')->default('open')->index(); // FeedbackStatus
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
