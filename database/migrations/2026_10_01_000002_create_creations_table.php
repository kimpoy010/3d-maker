<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('style_id')->constrained();
            $table->string('source_image_path');
            $table->string('status')->default('queued')->index();
            $table->string('provider_job_id')->nullable();
            $table->string('model_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('cost_credits');
            $table->unsignedTinyInteger('progress')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creations');
    }
};
