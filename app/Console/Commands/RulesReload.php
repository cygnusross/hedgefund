<?php

namespace App\Console\Commands;

use App\Domain\Rules\AlphaRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class RulesReload extends Command
{
    protected $signature = 'rules:reload {--show : Dump key values after reload}';

    protected $description = 'Reload alpha rules YAML from storage and update runtime singleton.';

    public function handle(AlphaRules $rules): int
    {
        try {
            $rules->reload();
        } catch (\Throwable $e) {
            $this->error('Failed to reload rules: '.$e->getMessage());

            return 1;
        }

        $meta = $rules->meta();
        $this->line('checksum: '.($meta['checksum'] ?? '<none>'));
        $this->line('loaded_at: '.($meta['loaded_at'] ?? '<none>'));

        Log::debug('alpha_rules_loaded', $meta);

        if ($this->option('show')) {
            $this->line('gates.news_threshold: '.json_encode($rules->get('gates.news_threshold')));
            $this->line('gates.adx_min: '.json_encode($rules->get('gates.adx_min')));
            $this->line('risk.per_trade_pct: '.json_encode($rules->get('risk.per_trade_pct')));
            $this->line('risk.per_trade_cap_pct: '.json_encode($rules->get('risk.per_trade_cap_pct')));
            $this->line('execution.rr: '.json_encode($rules->get('execution.rr')));
            $this->line('execution.spread_ceiling_pips: '.json_encode($rules->get('execution.spread_ceiling_pips')));
        }

        return 0;
    }
}
