<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_ledger', function (Blueprint $table) {
            $table->foreignId('stylization_id')->nullable()->constrained()->nullOnDelete();

            // One charge row and one refund row per stylization; NULLs never collide.
            $table->unique(['stylization_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::table('credit_ledger', function (Blueprint $table) {
            $table->dropUnique(['stylization_id', 'reason']);
            $table->dropConstrainedForeignId('stylization_id');
        });
    }
};
