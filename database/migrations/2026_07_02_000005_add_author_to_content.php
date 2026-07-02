<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Author of user-generated content: official cards/questions have a null user_id; a custom
     * one (is_custom = true) belongs to the user who made it and only appears in THEIR games.
     * Deleting the user removes their custom content.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
        });
        Schema::table('questions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
