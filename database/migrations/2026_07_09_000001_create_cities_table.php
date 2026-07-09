<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();              // stable slug used by the game (e.g. 'budapest')
            $table->string('name');                       // display name ('Budapest')
            $table->decimal('lat', 10, 7);                // play-area centre
            $table->decimal('lng', 10, 7);
            $table->string('image')->nullable();          // uploaded cover photo (storage path)
            $table->string('default_size')->default('medium'); // GameSize tied to the city (admin-set)
            $table->json('available_modes');              // transit modes that exist here (subset of the mode enum)
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
