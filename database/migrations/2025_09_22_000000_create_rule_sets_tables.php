<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_sets', function (Blueprint $table) {
            $table->id();
            $table->string('tag')->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->json('base_rules');
            $table->json('market_overrides')->nullable();
            $table->json('emergency_overrides')->nullable();
            $table->json('metrics')->nullable();
            $table->json('risk_bands')->nullable();
            $table->json('regime_snapshot')->nullable();
            $table->json('provenance')->nullable();
            $table->json('model_artifacts')->nullable();
            $table->boolean('shadow_mode')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->string('feature_hash')->nullable();
            $table->string('mc_seed')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('rule_set_regimes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_set_id')->constrained('rule_sets')->cascadeOnDelete();
            $table->string('week_tag');
            $table->json('metrics')->nullable();
            $table->timestamps();
            $table->unique(['rule_set_id', 'week_tag']);
        });

        Schema::create('rule_set_rollbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_set_id')->constrained('rule_sets')->cascadeOnDelete();
            $table->string('rollback_trigger');
            $table->json('payload')->nullable();
            $table->timestamp('rolled_back_at')->useCurrent();
        });

        Schema::create('rule_set_feature_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_set_id')->constrained('rule_sets')->cascadeOnDelete();
            $table->string('market');
            $table->string('feature_hash');
            $table->string('storage_path');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['rule_set_id', 'market']);
        });

        Schema::table('rule_sets', function (Blueprint $table) {
            $table->index(['period_start', 'period_end']);
            $table->index('is_active');
        });

        $connection = Schema::getConnection()->getDriverName();
        if (in_array($connection, ['sqlite', 'pgsql', 'mysql'])) {
            $statement = match ($connection) {
                'mysql' => 'CREATE UNIQUE INDEX rule_sets_active_unique ON rule_sets (is_active) WHERE is_active = 1',
                'pgsql' => 'CREATE UNIQUE INDEX rule_sets_active_unique ON rule_sets (is_active) WHERE is_active = true',
                default => 'CREATE UNIQUE INDEX IF NOT EXISTS rule_sets_active_unique ON rule_sets (is_active) WHERE is_active = 1',
            };

            DB::statement($statement);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_set_feature_snapshots');
        Schema::dropIfExists('rule_set_rollbacks');
        Schema::dropIfExists('rule_set_regimes');
        Schema::dropIfExists('rule_sets');
    }
};
