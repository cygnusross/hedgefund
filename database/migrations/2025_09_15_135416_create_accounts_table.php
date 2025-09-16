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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g., 'Sleeve', 'Safe', 'Growth', 'Conservative'
            $table->string('type')->default('trading'); // 'trading', 'cash', 'reserve'
            $table->decimal('balance', 15, 2); // Current balance
            $table->decimal('initial_balance', 15, 2); // Starting balance for tracking performance
            $table->decimal('available_balance', 15, 2)->nullable(); // Available for new positions (balance - used_margin)
            $table->decimal('used_margin', 15, 2)->default(0); // Currently used margin
            $table->string('currency', 3)->default('GBP'); // Account currency
            $table->boolean('is_active')->default(true);
            $table->decimal('max_risk_per_trade_pct', 5, 2)->default(2.00); // Max risk per trade (e.g., 2%)
            $table->decimal('max_portfolio_risk_pct', 5, 2)->default(20.00); // Max total portfolio risk
            $table->text('description')->nullable(); // Optional description
            $table->timestamps();

            // Indexes
            $table->index(['is_active']);
            $table->index(['type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
