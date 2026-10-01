<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creations', function (Blueprint $table) {
            // STL used for printing; never shown to customers in this release.
            $table->string('print_model_path')->nullable()->after('model_path');
        });
    }

    public function down(): void
    {
        Schema::table('creations', function (Blueprint $table) {
            $table->dropColumn('print_model_path');
        });
    }
};
