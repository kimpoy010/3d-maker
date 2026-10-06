<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clickers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clicker_body_id')->constrained('clicker_bodies');
            $table->string('name');
            $table->string('artwork_path')->nullable();
            $table->json('settings');
            $table->string('thumbnail_path')->nullable();
            $table->string('status')->default('draft');
            $table->string('build_error')->nullable();
            $table->string('threemf_path')->nullable();
            $table->string('stl_zip_path')->nullable();
            $table->timestamp('downloads_unlocked_at')->nullable();
            // How many times the user has paid to unlock this design (each is one numbered ledger attempt).
            $table->unsignedSmallInteger('unlock_attempts')->default(0);
            // Set when a build starts; the sweeper fails builds that stay "building" too long.
            $table->timestamp('build_started_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'build_started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clickers');
    }
};
