<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clicker_bodies', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('preview_image')->nullable();
            // Paths relative to the project root, inside resources/clicker/bodies/.
            $table->json('model_files');
            // Cap, artwork area and print limits, all in millimetres. For static bodies see ClickerBody::legacyConfig().
            $table->json('config');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clicker_bodies');
    }
};
