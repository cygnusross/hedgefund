<?php

declare(strict_types=1);

namespace App\Domain\Rules\Calibration;

use App\Models\Market;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class FeatureSnapshotService
{
    public function __construct(private readonly int $windowDays = 20) {}

    public function build(CalibrationConfig $config): CalibrationDataset
    {
        $markets = $config->markets ?: Market::query()->where('is_active', true)->pluck('symbol')->all();
        if (empty($markets)) {
            $markets = ['GLOBAL'];
        }

        $baseDir = "rules/features/{$config->tag}";
        Storage::disk('local')->deleteDirectory($baseDir);
        Storage::disk('local')->makeDirectory($baseDir);

        $snapshots = [];
        $costs = [];
        $regimeSummary = [
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'window_days' => $this->windowDays,
            'markets' => count($markets),
        ];

        foreach ($markets as $symbol) {
            $payload = $this->buildSnapshotPayload($config, $symbol);
            $relativePath = sprintf('%s/%s.json', $baseDir, Str::slug($symbol));
            Storage::disk('local')->put($relativePath, json_encode($payload, JSON_PRETTY_PRINT));

            $absolute = Storage::disk('local')->path($relativePath);
            if (! file_exists($absolute)) {
                Log::warning('feature_snapshot_missing_file', ['path' => $absolute]);

                continue;
            }

            $hash = hash_file('sha256', $absolute);
            $snapshots[$symbol] = [
                'storage_path' => $relativePath,
                'feature_hash' => $hash,
                'metadata' => [
                    'byte_length' => strlen(json_encode($payload)),
                    'bars_5m' => $payload['metrics']['bars_5m'],
                    'bars_30m' => $payload['metrics']['bars_30m'],
                    'volatility_score' => $payload['metrics']['volatility_score'],
                ],
            ];

            $costs[$symbol] = $payload['metrics']['cost_estimate'];
        }

        return new CalibrationDataset(
            tag: $config->tag,
            markets: $markets,
            snapshots: $snapshots,
            regimeSummary: $regimeSummary,
            costEstimates: $costs,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSnapshotPayload(CalibrationConfig $config, string $market): array
    {
        $now = CarbonImmutable::now('UTC');
        $seed = crc32($market.$config->tag);
        mt_srand($seed);

        $bars5 = $this->windowDays * 12 * 5; // approx 12 hours/day of 5m bars
        $bars30 = max(1, (int) floor($bars5 / 6));
        $volatilityScore = round(mt_rand(10, 40) / 10, 2);
        $adxRegime = mt_rand(12, 35);
        $spreadMedian = round(mt_rand(8, 18) / 10, 2);

        return [
            'tag' => $config->tag,
            'market' => $market,
            'generated_at' => $now->toIso8601String(),
            'window_days' => $this->windowDays,
            'metrics' => [
                'bars_5m' => $bars5,
                'bars_30m' => $bars30,
                'volatility_score' => $volatilityScore,
                'adx_regime' => $adxRegime,
                'spread_median' => $spreadMedian,
                'cost_estimate' => max(0.01, min(0.15, $spreadMedian / 20)),
            ],
        ];
    }
}
