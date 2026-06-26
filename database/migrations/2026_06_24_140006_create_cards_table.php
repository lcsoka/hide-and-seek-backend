<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every hider-deck card: curses, powerups, and time-bonuses in one table.
        Schema::create('cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type')->default('curse');         // curse | powerup | time_bonus
            $table->string('key')->nullable()->unique();      // stable, locale-independent id
            $table->text('name');                             // translatable (spatie JSON)
            $table->text('cost')->nullable();                 // translatable casting cost
            $table->text('description');                      // translatable effect
            $table->json('effect')->nullable();               // structured consequence config
            $table->string('power')->nullable();              // powerups: veto/randomize/…
            $table->unsignedInteger('minutes')->nullable();   // time-bonuses: minutes added
            $table->unsignedInteger('count')->default(1);     // copies of this card in the deck
            $table->boolean('is_custom')->default(false);     // false = seeded official
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
