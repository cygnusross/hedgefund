<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            if (! Schema::hasColumn('markets', 'atr_min_pips_override')) {
                $table->float('atr_min_pips_override')->nullable()->after('price_scale');
            }
            if (! Schema::hasColumn('markets', 'adx_min_override')) {
                $table->float('adx_min_override')->nullable()->after('atr_min_pips_override');
            }
            if (! Schema::hasColumn('markets', 'z_abs_max_override')) {
                $table->float('z_abs_max_override')->nullable()->after('adx_min_override');
            }
        });
    }

    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            if (Schema::hasColumn('markets', 'atr_min_pips_override')) {
                $table->dropColumn('atr_min_pips_override');
            }
            if (Schema::hasColumn('markets', 'adx_min_override')) {
                $table->dropColumn('adx_min_override');
            }
            if (Schema::hasColumn('markets', 'z_abs_max_override')) {
                $table->dropColumn('z_abs_max_override');
            }
        });
    }
};
