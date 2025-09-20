<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('news_stats');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // We won't recreate the news_stats table as we're removing news functionality
        // This migration is irreversible
    }
};
