<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('spreads', function (Blueprint $table) {
            $table->id();
            $table->string('pair', 10)->index(); // 'EUR/USD'
            $table->decimal('spread_pips', 8, 5); // e.g., 1.20000
            $table->timestamp('recorded_at')->index();
            $table->timestamps();

            $table->unique(['pair', 'recorded_at'], 'spreads_unique_key');
            $table->index(['pair', 'recorded_at'], 'spreads_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spreads');
    }
};
