<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->nullable()->unique();     // stable, locale-independent id
            $table->text('name');                             // translatable (spatie JSON)
            $table->text('cost')->nullable();                 // translatable casting cost
            $table->text('description');                      // translatable effect
            $table->json('parameters')->nullable();           // structured effect data (future)
            $table->boolean('is_custom')->default(false);     // false = seeded official
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curses');
    }
};
