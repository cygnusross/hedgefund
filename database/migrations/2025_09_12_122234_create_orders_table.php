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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('deal_reference')->unique();
            $table->string('currency_code', 3);
            $table->enum('direction', ['BUY', 'SELL']);
            $table->string('epic');
            $table->string('expiry');
            $table->boolean('force_open')->default(false);
            $table->timestamp('good_till_date')->nullable();
            $table->boolean('guaranteed_stop')->default(false);
            $table->decimal('level', 15, 5);
            $table->decimal('limit_distance', 10, 2)->nullable();
            $table->decimal('limit_level', 15, 5)->nullable();
            $table->decimal('size', 15, 8);
            $table->decimal('stop_distance', 10, 2)->nullable();
            $table->decimal('stop_level', 15, 5)->nullable();
            $table->enum('time_in_force', ['GOOD_TILL_CANCELLED', 'GOOD_TILL_DATE']);
            $table->enum('type', ['LIMIT', 'STOP']);
            $table->enum('status', ['PENDING', 'FILLED', 'CANCELLED', 'REJECTED'])->default('PENDING');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
