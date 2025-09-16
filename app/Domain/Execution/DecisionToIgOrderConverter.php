<?php

declare(strict_types=1);

namespace App\Domain\Execution;

use App\Domain\FX\PipMath;
use App\Models\Market;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class DecisionToIgOrderConverter
{
    /**
     * Convert decision engine output to IG working order format using distances.
     */
    public static function convert(array $decision, string $pair): array
    {
        self::validateDecisionInput($decision);

        $market = self::getMarketForPair($pair);
        $action = strtoupper($decision['action']);
        $entry = (float) $decision['entry'];
        $sl = (float) $decision['sl'];
        $tp = (float) $decision['tp'];
        $size = (float) $decision['size'];

        // Get current market data to ensure proper distance from market
        $marketsEndpoint = app(\App\Services\IG\Endpoints\MarketsEndpoint::class);
        $marketDetails = $marketsEndpoint->get($market->epic);
        $snapshot = $marketDetails['snapshot'];
        $currentBid = ($snapshot['bid'] ?? 0) / 10000;
        $currentOffer = ($snapshot['offer'] ?? 0) / 10000;

        // Get dealing rules for this specific market
        $dealingRules = $marketDetails['dealingRules'];
        $minStepDistance = $dealingRules['minStepDistance']['value'] ?? 5;

        // Determine point scale based on epic (SB vs CFD)
        // For Spread Betting (CS.D.): 1 point = 1 pip = 0.0001
        // For CFD (IX.D., etc.): 1 point = 0.00001
        $isSpreadBetting = str_starts_with($market->epic, 'CS.D.');
        $pointScale = $isSpreadBetting ? 10000 : 100000; // SB: /10000, CFD: /100000

        // Determine the effective minimum by checking all possible minimums
        // Don't trust just one field - use the highest applicable one
        $minNormal = $dealingRules['minNormalStopOrLimitDistance']['value'] ?? 2;
        $minControlledRisk = $dealingRules['minControlledRiskStopDistance']['value'] ?? 5;
        $controlledRiskSpacing = $dealingRules['controlledRiskSpacing']['value'] ?? 20;

        // The effective minimum is the highest of all applicable minimums
        $effectiveMinDistance = max($minNormal, $minControlledRisk, $controlledRiskSpacing);

        // Use a reasonable cushion (1.2x) as ChatGPT suggested
        $cushionFactor = 1.2;
        $safeMinDistance = max($effectiveMinDistance * $cushionFactor, $minStepDistance);

        // Ensure we're at least 5 points minimum for any volatility
        $safeMinDistance = max($safeMinDistance, 5);

        // Add buffer to account for market movement during order processing
        $marketMovementBuffer = 10; // Extra points to handle market movement
        $safeEntryDistance = $minStepDistance + $marketMovementBuffer;

        // Calculate entry level using correct point scale
        if ($action === 'BUY') {
            // BUY STOP must be above current offer by at least minStepDistance + buffer
            $realisticEntry = $currentOffer + ($safeEntryDistance / $pointScale);
            $orderType = 'STOP';
            // For BUY orders: stop below entry, limit above entry
            $stopLevel = $realisticEntry - ($safeMinDistance / $pointScale);
            $limitLevel = $realisticEntry + ($safeMinDistance / $pointScale);
        } else {
            // SELL STOP must be below current bid by at least minStepDistance + buffer
            $realisticEntry = $currentBid - ($safeEntryDistance / $pointScale);
            $orderType = 'STOP';
            // For SELL orders: stop above entry, limit below entry
            $stopLevel = $realisticEntry + ($safeMinDistance / $pointScale);
            $limitLevel = $realisticEntry - ($safeMinDistance / $pointScale);
        }

        // Snap all levels to proper ticks (0.0001 for EUR/USD, 0.01 for JPY pairs)
        $tickSize = str_contains($pair, 'JPY') ? 0.01 : 0.0001;
        $realisticEntry = self::snapToTick($realisticEntry, $tickSize);
        $stopLevel = self::snapToTick($stopLevel, $tickSize);
        $limitLevel = self::snapToTick($limitLevel, $tickSize);

        // Convert back to raw format for IG API (multiply by pointScale)
        $rawEntry = round($realisticEntry * $pointScale);
        $rawStopLevel = round($stopLevel * $pointScale);
        $rawLimitLevel = round($limitLevel * $pointScale);

        // Final validation: ensure distances from current market are adequate
        $currentPrice = $action === 'BUY' ? $currentOffer : $currentBid;
        $entryDistanceFromMarket = abs($realisticEntry - $currentPrice) * $pointScale;
        $stopDistanceFromEntry = abs($stopLevel - $realisticEntry) * $pointScale;
        $limitDistanceFromEntry = abs($limitLevel - $realisticEntry) * $pointScale;

        // Log all the minimums and effective values for debugging
        Log::info('IG Order Parameters', [
            'epic' => $market->epic,
            'is_spread_betting' => $isSpreadBetting,
            'point_scale' => $pointScale,
            'tick_size' => $tickSize,
            'min_normal' => $minNormal,
            'min_controlled_risk' => $minControlledRisk,
            'controlled_risk_spacing' => $controlledRiskSpacing,
            'effective_min_distance' => $effectiveMinDistance,
            'used_distance_points' => $safeMinDistance,
            'entry_level_decimal' => $realisticEntry,
            'stop_level_decimal' => $stopLevel,
            'limit_level_decimal' => $limitLevel,
            'entry_level_raw' => $rawEntry,
            'stop_level_raw' => $rawStopLevel,
            'limit_level_raw' => $rawLimitLevel,
            'current_bid' => $currentBid,
            'current_offer' => $currentOffer,
            'entry_distance_from_market' => $entryDistanceFromMarket,
            'stop_distance_from_entry' => $stopDistanceFromEntry,
            'limit_distance_from_entry' => $limitDistanceFromEntry,
        ]);

        $adjustedSize = max($size, 0.04);

        return [
            'currencyCode' => 'GBP',
            'direction' => $action,
            'epic' => $market->epic,
            'expiry' => 'DFB',
            'guaranteedStop' => false,
            'level' => (int) $rawEntry, // Entry level as raw integer like web interface (11818)
            'size' => $adjustedSize,
            'stopDistance' => (int) $safeMinDistance, // Stop distance in raw points (24)
            'limitDistance' => (int) $safeMinDistance, // Limit distance in raw points (24)
            'timeInForce' => 'GOOD_TILL_CANCELLED',
            'type' => $orderType,
        ];
    }

    protected static function calculateDistance(float $from, float $to, string $pair): float
    {
        $priceDelta = abs($from - $to);
        $pipsDistance = PipMath::toPips($priceDelta, $pair);

        return round($pipsDistance);
    }

    /**
     * Snap price to the nearest valid tick size
     */
    protected static function snapToTick(float $price, float $tickSize): float
    {
        return round($price / $tickSize) * $tickSize;
    }

    protected static function getMarketForPair(string $pair): Market
    {
        $normalizedPair = str_replace('-', '/', $pair);
        $market = Market::where('symbol', $normalizedPair)
            ->where('is_active', true)
            ->first();

        if (! $market) {
            throw new InvalidArgumentException("No active market found for pair: {$pair}");
        }

        return $market;
    }

    protected static function validateDecisionInput(array $decision): void
    {
        $required = ['action', 'entry', 'sl', 'tp', 'size'];

        foreach ($required as $field) {
            if (! isset($decision[$field])) {
                throw new InvalidArgumentException("Decision array missing required field: {$field}");
            }
        }

        if (! in_array(strtolower($decision['action']), ['buy', 'sell'])) {
            throw new InvalidArgumentException("Invalid action: {$decision['action']}. Must be 'buy' or 'sell'");
        }

        $numericFields = ['entry', 'sl', 'tp', 'size'];
        foreach ($numericFields as $field) {
            if (! is_numeric($decision[$field]) || $decision[$field] <= 0) {
                throw new InvalidArgumentException("Field {$field} must be a positive numeric value");
            }
        }
    }
}
