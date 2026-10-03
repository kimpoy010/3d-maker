<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creations', function (Blueprint $table) {
            // Set once the owner pays to download the GLB and STL of this creation.
            $table->timestamp('downloads_unlocked_at')->nullable()->after('print_model_path');
        });

        // Models built before downloads were charged for stay downloadable.
        DB::table('creations')->where('status', 'succeeded')->update(['downloads_unlocked_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('creations', function (Blueprint $table) {
            $table->dropColumn('downloads_unlocked_at');
        });
    }
};
