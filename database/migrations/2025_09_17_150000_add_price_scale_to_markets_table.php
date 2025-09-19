<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            if (! Schema::hasColumn('markets', 'price_scale')) {
                $table->unsignedBigInteger('price_scale')->nullable()->after('currencies');
            }
        });
    }

    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            if (Schema::hasColumn('markets', 'price_scale')) {
                $table->dropColumn('price_scale');
            }
        });
    }
};
