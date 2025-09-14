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
        // Ensure the hash column is present before adding unique index
        if (Schema::hasColumn('calendar_events', 'hash')) {
            try {
                Schema::table('calendar_events', function (Blueprint $table) {
                    $table->unique('hash', 'calendar_events_hash_unique');
                });
            } catch (\Throwable $e) {
                // Ignore errors (index may already exist or SQLite may not support the operation)
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('calendar_events', 'hash')) {
            try {
                Schema::table('calendar_events', function (Blueprint $table) {
                    $table->dropUnique('calendar_events_hash_unique');
                });
            } catch (\Throwable $e) {
                // Ignore errors when rolling back (index may not exist)
            }
        }
    }
};
