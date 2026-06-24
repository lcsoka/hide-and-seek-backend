<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->nullable()->unique();    // stable, locale-independent id
            $table->string('category')->index();            // QuestionCategory
            $table->text('title');                           // translatable (spatie JSON)
            $table->text('prompt');                          // translatable (spatie JSON)
            $table->unsignedTinyInteger('reward_draw')->default(0); // cards drawn
            $table->unsignedTinyInteger('reward_keep')->default(0); // cards kept
            $table->json('parameters')->nullable();          // distance/radius options etc.
            $table->boolean('is_custom')->default(false);    // false = seeded official
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
