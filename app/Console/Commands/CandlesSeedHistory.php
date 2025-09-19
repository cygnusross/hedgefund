<?php

namespace App\Console\Commands;

use App\Models\Candle;
use App\Models\Market;
use App\Services\Prices\PriceProvider;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CandlesSeedHistory extends Command
{
    protected $signature = 'candles:seed-history {pair?} {--no-rate-limit : Skip rate limiting (for testing)}';

    protected $description = 'Seed 3 years of historical candle data (5min and 30min intervals) for a trading pair or all active markets';

    private bool $hadFailure = false;

    public function __construct(
        protected PriceProvider $priceProvider
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->hadFailure = false;
        $pair = $this->argument('pair');

        // If no pair specified, get all active markets
        if (! $pair) {
            $markets = Market::where('is_active', true)->pluck('symbol')->toArray();

            if (empty($markets)) {
                $this->error('No active markets found in the database.');

                return self::FAILURE;
            }

            $this->info('No pair specified. Processing all active markets: '.implode(', ', $markets));

            $totalInsertedAllMarkets = 0;

            foreach ($markets as $marketSymbol) {
                $this->info("🔄 Processing market: {$marketSymbol}");

                $totalInserted = $this->processMarket($marketSymbol);
                if ($totalInserted === null) {
                    $this->warn("⚠️ Failed to seed {$marketSymbol}. See logs for details.");
                    $this->newLine();

                    continue;
                }

                $totalInsertedAllMarkets += $totalInserted;

                $this->info("✅ Completed {$marketSymbol}. Inserted: {$totalInserted} candles");
                $this->newLine();
            }

            $this->info("🎉 Completed all markets. Total new candles inserted: {$totalInsertedAllMarkets}");

            return $this->hadFailure ? self::FAILURE : self::SUCCESS;
        }

        // Process single market
        $totalInserted = $this->processMarket($pair);
        if ($totalInserted === null) {
            return self::FAILURE;
        }

        $this->info("✅ Completed seeding for {$pair}. Total new candles inserted: {$totalInserted}");

        return self::SUCCESS;
    }

    /**
     * Process a single market for historical data seeding.
     *
     * @return int|null Total candles inserted, or null when the provider fails.
     */
    protected function processMarket(string $pair): ?int
    {
        $intervals = ['5min', '30min'];

        $this->info("Seeding 3 years of historical candle data for {$pair} (5min and 30min intervals)...");

        $totalInsertedAll = 0;

        try {
            foreach ($intervals as $interval) {
                $this->info("Processing {$interval} interval...");

                // Set end time to market close (22:00 UTC) on current day
                $endTime = Carbon::now('UTC')->setTime(22, 0, 0);

                // Set start time to market open (07:00 UTC) 3 years ago
                $startTime = $endTime->clone()->subYears(3)->setTime(7, 0, 0);

                // Check existing data coverage
                $existingCount = $this->getExistingCandleCount($pair, $interval, $startTime, $endTime);
                if ($existingCount > 0) {
                    $this->info("Found {$existingCount} existing {$interval} candles. Will skip duplicates during insert.");
                }

                // Calculate chunks needed (stay under 5000 records per API call)
                $totalInserted = $this->seedCandleData($pair, $interval, $startTime, $endTime);
                $totalInsertedAll += $totalInserted;

                $this->info("Successfully inserted {$totalInserted} new {$interval} candles for {$pair}");

                $this->logSeedingResults($pair, $interval, $totalInserted, $existingCount);
            }

            return $totalInsertedAll;
        } catch (\Exception $e) {
            $this->error('Failed to seed candles: '.$e->getMessage());

            Log::error('Historical candle seeding failed', [
                'pair' => $pair,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->hadFailure = true;

            return null;
        }
    }

    /**
     * Get existing candle count for the given period
     */
    protected function getExistingCandleCount(string $pair, string $interval, Carbon $start, Carbon $end): int
    {
        return Candle::forPair($pair)
            ->forInterval($interval)
            ->betweenDates($start, $end)
            ->count();
    }

    /**
     * Seed candle data using chunked API calls with rate limiting
     */
    protected function seedCandleData(string $pair, string $interval, Carbon $startTime, Carbon $endTime): int
    {
        $totalInserted = 0;
        $chunks = $this->calculateDateChunks($interval, $startTime, $endTime);

        $this->info('Processing '.count($chunks).' date chunks with rate limiting (25-30 calls/min)...');

        $progressBar = $this->output->createProgressBar(count($chunks));
        $progressBar->start();

        foreach ($chunks as $chunkIndex => $chunk) {
            // Rate limiting: 25-30 calls per minute = 2-2.5 second delays
            if ($chunkIndex > 0 && ! $this->option('no-rate-limit')) {
                $this->comment(' [Rate limiting: waiting 2.5 seconds...]');
                usleep(2500000); // 2.5 seconds in microseconds
            }

            try {
                $candles = $this->fetchCandlesForChunk($pair, $interval, $chunk);

                if (! empty($candles)) {
                    $inserted = $this->bulkInsertCandles($pair, $interval, $candles);
                    $totalInserted += $inserted;
                }

                // Free memory after processing each chunk
                unset($candles);
            } catch (\Exception $e) {
                $this->error('Chunk failed: '.$e->getMessage());
                Log::warning('Candle seeding chunk failed', [
                    'pair' => $pair,
                    'interval' => $interval,
                    'chunk' => $chunk,
                    'error' => $e->getMessage(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        // Force garbage collection after processing all chunks
        gc_collect_cycles();

        return $totalInserted;
    }

    /**
     * Calculate date chunks to stay under API limits
     */
    protected function calculateDateChunks(string $interval, Carbon $startTime, Carbon $endTime): array
    {
        $chunks = [];
        $current = $startTime->clone();

        // Estimate records per day for chunking (only market hours: 07:00-22:00 = 15 hours)
        $recordsPerDay = $interval === '5min' ? 180 : 30; // 15 hours * (12 or 2) records per hour
        $daysPerChunk = min(90, floor(4800 / $recordsPerDay)); // Stay under 5000 with buffer

        while ($current->lt($endTime)) {
            $chunkEnd = $current->clone()->addDays($daysPerChunk);
            if ($chunkEnd->gt($endTime)) {
                $chunkEnd = $endTime;
            }

            // Ensure chunk boundaries respect market hours (07:00-22:00 UTC)
            $chunkStart = $current->clone()->setTime(7, 0, 0);
            $chunkEndAdjusted = $chunkEnd->clone()->setTime(22, 0, 0);

            $chunks[] = [
                'start' => $chunkStart->format('Y-m-d H:i:s'),
                'end' => $chunkEndAdjusted->format('Y-m-d H:i:s'),
            ];

            $current = $chunkEnd->clone()->addDay()->setTime(7, 0, 0);
        }

        return $chunks;
    }

    /**
     * Fetch candles for a specific date chunk
     */
    protected function fetchCandlesForChunk(string $pair, string $interval, array $chunk): array
    {
        $params = [
            'interval' => $interval,
            'outputsize' => 5000,
            'start_date' => $chunk['start'],
            'end_date' => $chunk['end'],
            'timezone' => 'UTC',
        ];

        return $this->priceProvider->getCandles($pair, $params);
    }

    /**
     * Bulk insert candles with memory-efficient chunked processing
     */
    protected function bulkInsertCandles(string $pair, string $interval, array $bars): int
    {
        if (empty($bars)) {
            return 0;
        }

        $now = now();
        $filteredCount = 0;
        $totalInserted = 0;
        $beforeCount = Candle::forPair($pair)->forInterval($interval)->count();

        // Process bars in memory-efficient chunks of 1000
        foreach (array_chunk($bars, 1000) as $chunk) {
            $candleData = [];

            foreach ($chunk as $bar) {
                $timestamp = Carbon::parse($bar->ts);
                $hour = $timestamp->hour;

                // Only include candles within London market hours (07:00-22:00 UTC)
                if ($hour < 7 || $hour > 22) {
                    $filteredCount++;

                    continue;
                }

                $candleData[] = [
                    'pair' => $pair,
                    'interval' => $interval,
                    'timestamp' => $bar->ts,
                    'open' => $bar->open,
                    'high' => $bar->high,
                    'low' => $bar->low,
                    'close' => $bar->close,
                    'volume' => $bar->volume,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Insert this chunk if not empty
            if (! empty($candleData)) {
                DB::table('candles')->upsert(
                    $candleData,
                    ['pair', 'interval', 'timestamp'], // Unique key columns
                    ['open', 'high', 'low', 'close', 'volume', 'updated_at'] // Columns to update
                );

                // Force immediate garbage collection after each database insert
                gc_collect_cycles();
            }

            // Free memory for this chunk
            unset($candleData, $chunk);
        }

        // Force garbage collection to free memory
        gc_collect_cycles();

        if ($filteredCount > 0) {
            Log::info('Filtered out candles outside market hours', [
                'pair' => $pair,
                'interval' => $interval,
                'filtered_count' => $filteredCount,
            ]);
        }

        $afterCount = Candle::forPair($pair)->forInterval($interval)->count();

        return $afterCount - $beforeCount;
    }

    /**
     * Log seeding results
     */
    protected function logSeedingResults(string $pair, string $interval, int $inserted, int $existing): void
    {
        Log::info('Historical candle seeding completed', [
            'pair' => $pair,
            'interval' => $interval,
            'period' => '3 years',
            'inserted' => $inserted,
            'existing_skipped' => $existing,
            'total_after' => $inserted + $existing,
        ]);
    }
}
