<?php

namespace App\Console\Commands;

use App\Domain\Rules\AlphaRules;
use Illuminate\Console\Command;

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

        // alpha rules reloaded; metadata printed to console when requested

        if ($this->option('show')) {
            $this->line('gates.news_threshold: '.json_encode($rules->get('gates.news_threshold')));
            $this->line('gates.adx_min: '.json_encode($rules->get('gates.adx_min')));
            $this->line('risk.per_trade_pct: '.json_encode($rules->get('risk.per_trade_pct')));
            $this->line('risk.per_trade_cap_pct: '.json_encode($rules->get('risk.per_trade_cap_pct')));
            $this->line('execution.rr: '.json_encode($rules->get('execution.rr')));
            $this->line('execution.spread_ceiling_pips: '.json_encode($rules->get('execution.spread_ceiling_pips')));
            $this->line('execution.sl_min_pips: '.json_encode($rules->get('execution.sl_min_pips')));
        }

        return 0;
    }
}
