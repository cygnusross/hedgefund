<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Rules\RuleResolver;
use App\Domain\Rules\RuleSetRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class RulesActivate extends Command
{
    protected $signature = 'rules:activate {tag} {--production : Activate in production mode (requires confirmation)} {--shadow : Activate in shadow mode for observation only}';

    protected $description = 'Activate a specific rule set for trading (rollback capability)';

    public function __construct(
        private readonly RuleSetRepository $repository,
        private readonly RuleResolver $resolver
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tag = $this->argument('tag');
        $shadowMode = (bool) $this->option('shadow');
        $productionMode = (bool) $this->option('production');

        if (! is_string($tag) || empty($tag)) {
            $this->error('Invalid tag provided. Tag must be a non-empty string.');

            return self::FAILURE;
        }

        // Check if the rule set exists
        $ruleSet = $this->repository->findByTag($tag);
        if ($ruleSet === null) {
            $this->line(sprintf('Rule set with tag "%s" not found.', $tag));
            $this->info('Available rule sets:');

            $recent = $this->repository->latest(10);
            if ($recent->isEmpty()) {
                $this->line('  No rule sets found in database.');
            } else {
                foreach ($recent as $rule) {
                    $status = $rule->is_active ? ' [ACTIVE]' : '';
                    $this->line("  {$rule->tag} - {$rule->created_at->format('Y-m-d H:i')}{$status}");
                }
            }

            return self::FAILURE;
        }

        try {
            if ($productionMode) {
                // Resolve ruleset for summary display
                $resolved = $this->resolver->getByTag($tag, false);
                if ($resolved !== null) {
                    $this->showRuleSetSummary($ruleSet, $resolved);
                }

                // Ask explicit confirmation for production activation
                if (! $this->confirm("Activate rule set {$tag} in PRODUCTION mode?")) {
                    $this->info('Activation cancelled.');

                    return self::SUCCESS;
                }

                $this->activateProduction($tag, $ruleSet, true);
            } elseif ($shadowMode) {
                // Show summary when available for shadow mode as well
                $resolved = $this->resolver->getByTag($tag, false);
                if ($resolved !== null) {
                    $this->showRuleSetSummary($ruleSet, $resolved);
                }

                if (! $this->confirm("Activate rule set {$tag} in SHADOW mode?")) {
                    $this->info('Activation cancelled.');

                    return self::SUCCESS;
                }

                $this->activateShadowMode($tag, $ruleSet);
            } else {
                // Default: interactive switching (preserve existing behavior)
                $this->activateProduction($tag, $ruleSet);
            }
        } catch (\Throwable $e) {
            $this->error('Failed to activate rule set: '.$e->getMessage());
            Log::error('rules_activate_failed', [
                'tag' => $tag,
                'shadow_mode' => $shadowMode,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Activate rule set in shadow mode (observation only)
     */
    private function activateShadowMode(string $tag, $ruleSet): void
    {
        // Resolve and cache shadow rules
        $resolved = $this->resolver->getByTag($tag, false);
        if ($resolved === null) {
            throw new \RuntimeException("Unable to resolve rule set '{$tag}' for shadow mode.");
        }

        // Persist shadow activation on the model for test visibility
        if (method_exists($ruleSet, 'activateShadow')) {
            $ruleSet->activateShadow();
        }

        $this->info("Rule set '{$tag}' activated in shadow mode.");
        $this->warn('Shadow mode: Rules cached for observation but not set as production active.');
        $this->info('Decision engine will continue using production rules while logging shadow comparisons.');

        Log::info('rules_shadow_activated', [
            'tag' => $tag,
            'period_start' => $ruleSet->period_start?->toDateString(),
            'period_end' => $ruleSet->period_end?->toDateString(),
        ]);
    }

    /**
     * Activate rule set for production trading
     */
    private function activateProduction(string $tag, $ruleSet, bool $skipSwitchConfirm = false): void
    {
        // Show current active rule set if any
        $currentActive = $this->repository->findActive();
        if ($currentActive !== null && $currentActive->tag !== $tag) {
            $this->info("Currently active: {$currentActive->tag}");

            if (! $skipSwitchConfirm) {
                if (! $this->confirm("Switch from '{$currentActive->tag}' to '{$tag}'?")) {
                    $this->info('Activation cancelled.');

                    return;
                }
            }
        }

        // Perform the activation using the model's method (which handles DB transaction)
        if (method_exists($ruleSet, 'activate')) {
            $ruleSet->activate();
        } else {
            // Fallback behavior
            DB::transaction(function () use ($ruleSet) {
                \App\Models\RuleSet::query()->where('is_active', true)->where('id', '!=', $ruleSet->id)
                    ->update(['is_active' => false, 'deactivated_at' => now()]);

                $ruleSet->update(['is_active' => true, 'activated_at' => now(), 'shadow_mode' => false]);
            });
        }

        // Verify the resolver can load the newly activated rules
        $resolved = $this->resolver->getActive();
        if ($resolved === null || $resolved->tag !== $tag) {
            throw new \RuntimeException("Activation succeeded but rule resolver cannot load '{$tag}'.");
        }

        $this->info("Rule set '{$tag}' successfully activated for production trading.");

        // Show rule set details
        $this->showRuleSetSummary($ruleSet, $resolved);

        Log::info('rules_production_activated', [
            'tag' => $tag,
            'previous_active' => $currentActive?->tag,
            'period_start' => $ruleSet->period_start?->toDateString(),
            'period_end' => $ruleSet->period_end?->toDateString(),
        ]);
    }

    /**
     * Display summary of the activated rule set
     */
    private function showRuleSetSummary($ruleSet, $resolved): void
    {
        $this->newLine();
        // Use labels expected by tests
        $this->info("Rule Set: {$ruleSet->tag}");
        $this->line("Created: {$ruleSet->created_at->format('Y-m-d H:i:s')}");

        if ($ruleSet->period_start && $ruleSet->period_end) {
            $this->line("Period: {$ruleSet->period_start->toDateString()} to {$ruleSet->period_end->toDateString()}");
        }

        // Key parameters
        $baseRules = $resolved->base;
        if (is_array($baseRules)) {
            $this->line('ADX Minimum: '.($baseRules['gates']['adx_min'] ?? 'N/A'));
            $this->line('Sentiment Mode: '.($baseRules['gates']['sentiment']['mode'] ?? 'N/A'));
            $this->line('Risk-Reward Ratio: '.($baseRules['execution']['rr'] ?? 'N/A'));
        }

        if (is_array($ruleSet->metrics)) {
            $m = $ruleSet->metrics;
            $this->line('Total PnL: '.($m['total_pnl'] ?? 'N/A'));
            $this->line('Win Rate: '.(isset($m['win_rate']) ? number_format($m['win_rate'] * 100, 2).'%' : 'N/A'));
            $this->line('Max Drawdown: '.($m['max_drawdown_pct'] ?? 'N/A').'%');
            $this->line('Total Trades: '.($m['trades_total'] ?? 'N/A'));

            // Emit warnings for concerning metrics to aid human confirmation
            if (isset($m['total_pnl']) && $m['total_pnl'] < 0) {
                $this->line('⚠️  Warning: This rule set has negative total PnL ('.($m['total_pnl']).')');
            }
            if (isset($m['max_drawdown_pct']) && $m['max_drawdown_pct'] > 15) {
                $this->line('⚠️  Warning: This rule set has high drawdown ('.($m['max_drawdown_pct']).'%)');
            }
            if (isset($m['trades_total']) && $m['trades_total'] < 20) {
                $this->line('⚠️  Warning: This rule set has limited trading history ('.($m['trades_total']).' trades)');
            }
        }

        // Market overrides count
        $overrides = is_array($ruleSet->market_overrides) ? count($ruleSet->market_overrides) : 0;
        $this->line('Market Overrides: '.$overrides);

        $this->newLine();
        $this->info('Rule set activation complete.');
    }
}
