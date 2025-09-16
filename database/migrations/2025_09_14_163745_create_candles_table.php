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
        Schema::create('candles', function (Blueprint $table) {
            $table->id();
            $table->string('pair', 10)->index(); // e.g., 'EURUSD'
            $table->string('interval', 10)->index(); // e.g., '5min', '30min'
            $table->timestamp('timestamp')->index(); // Candle open time in UTC
            $table->decimal('open', 10, 5); // Opening price
            $table->decimal('high', 10, 5); // Highest price
            $table->decimal('low', 10, 5); // Lowest price
            $table->decimal('close', 10, 5); // Closing price
            $table->bigInteger('volume')->nullable(); // Trading volume
            $table->timestamps();

            // Composite unique index for idempotency
            $table->unique(['pair', 'interval', 'timestamp'], 'candles_unique_key');

            // Performance indexes for queries
            $table->index(['pair', 'interval', 'timestamp'], 'candles_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candles');
    }
};
