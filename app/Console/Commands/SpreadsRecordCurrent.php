<?php

namespace App\Console\Commands;

use App\Domain\FX\SpreadEstimator;
use App\Models\Market;
use App\Models\Spread;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SpreadsRecordCurrent extends Command
{
    protected $signature = 'spreads:record-current';

    protected $description = 'Record current spread data for all markets';

    public function __construct(
        protected SpreadEstimator $spreadEstimator
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Recording current spread data for all markets...');

        $markets = Market::where('is_active', true)->get();
        $recordedAt = Carbon::now();
        $spreads = [];
        $errors = 0;

        foreach ($markets as $market) {
            try {
                $spreadPips = $this->spreadEstimator->estimatePipsForPair($market->symbol, true);

                if ($spreadPips !== null) {
                    $spreads[] = [
                        'pair' => $market->epic,
                        'spread_pips' => $spreadPips,
                        'recorded_at' => $recordedAt,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $this->info("Recorded spread for {$market->epic}: {$spreadPips} pips");
                } else {
                    $this->info("Failed to get spread for {$market->epic}");
                    $errors++;
                }
            } catch (\Exception $e) {
                $this->info("Failed to get spread for {$market->epic}");
                $errors++;

                Log::warning('Spread recording failed for market', [
                    'market' => $market->symbol,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! empty($spreads)) {
            Spread::upsert(
                $spreads,
                ['pair', 'recorded_at'],
                ['spread_pips', 'updated_at']
            );

            $recorded = count($spreads);
            $this->info("Successfully recorded spreads for {$recorded} markets.");

            Log::info('Spread recording completed', [
                'recorded' => $recorded,
                'errors' => $errors,
                'recorded_at' => $recordedAt,
            ]);
        } else {
            $this->info('Successfully recorded spreads for 0 markets.');
        }

        if ($errors > 0) {
            $this->warn("Completed with {$errors} errors");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
