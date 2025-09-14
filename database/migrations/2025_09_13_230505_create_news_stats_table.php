<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_stats', function (Blueprint $table) {
            $table->id();
            $table->string('pair_norm', 15);
            $table->date('stat_date');
            $table->integer('pos')->default(0);
            $table->integer('neg')->default(0);
            $table->integer('neu')->default(0);
            $table->decimal('raw_score', 5, 2)->nullable();
            $table->decimal('strength', 5, 3)->nullable();
            $table->string('source', 50)->default('forexnewsapi');
            $table->timestamp('fetched_at')->nullable();
            $table->json('payload')->nullable();

            $table->unique(['pair_norm', 'stat_date']);
            $table->index('pair_norm');
            $table->index('stat_date');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_stats');
    }
};
