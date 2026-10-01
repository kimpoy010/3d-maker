<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stylizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('style_id')->constrained();
            $table->string('source_image_path')->nullable();
            $table->string('result_image_path')->nullable();
            $table->string('status')->default('queued');
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('cost_credits');
            $table->foreignId('creation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stylizations');
    }
};
