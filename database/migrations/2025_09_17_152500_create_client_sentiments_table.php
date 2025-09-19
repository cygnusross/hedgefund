<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_sentiments', function (Blueprint $table) {
            $table->id();
            $table->string('market_id')->index();
            $table->string('pair')->nullable()->index();
            $table->decimal('long_pct', 5, 2);
            $table->decimal('short_pct', 5, 2);
            $table->timestamp('recorded_at')->index();
            $table->timestamps();

            $table->unique(['market_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_sentiments');
    }
};
