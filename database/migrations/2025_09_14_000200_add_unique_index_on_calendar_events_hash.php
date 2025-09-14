<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('calendar_events')) {
            return;
        }

        try {
            Schema::table('calendar_events', function (Blueprint $table) {
                // add unique index for hash used by upsert
                $table->unique('hash', 'calendar_events_hash_unique');
            });
        } catch (\Throwable $e) {
            // Ignore "already exists" errors which may occur in sqlite/test setups
            if (stripos($e->getMessage(), 'already exists') === false) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('calendar_events')) {
            return;
        }

        try {
            Schema::table('calendar_events', function (Blueprint $table) {
                $table->dropUnique('calendar_events_hash_unique');
            });
        } catch (\Throwable $e) {
            // ignore errors about missing index during rollback in testing
            if (stripos($e->getMessage(), 'no such index') === false && stripos($e->getMessage(), 'does not exist') === false) {
                throw $e;
            }
        }
    }
};
