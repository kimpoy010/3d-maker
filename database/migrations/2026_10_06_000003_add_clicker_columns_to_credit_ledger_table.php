<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_ledger', function (Blueprint $table) {
            $table->foreignId('clicker_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('clicker_attempt')->nullable();

            // One charge row and at most one refund row per numbered attempt; NULLs never collide.
            $table->unique(['clicker_id', 'reason', 'clicker_attempt']);
        });
    }

    public function down(): void
    {
        Schema::table('credit_ledger', function (Blueprint $table) {
            // FK first: on MySQL the unique index backs the FK and cannot be dropped before it.
            $table->dropForeign(['clicker_id']);
            $table->dropUnique(['clicker_id', 'reason', 'clicker_attempt']);
            $table->dropColumn(['clicker_id', 'clicker_attempt']);
        });
    }
};
