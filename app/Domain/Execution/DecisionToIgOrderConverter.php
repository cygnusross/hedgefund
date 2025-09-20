<?php

declare(strict_types=1);

namespace App\Domain\Execution;

use App\Domain\FX\PipMath;
use App\Models\Market;
use App\Support\Math\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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
        $size = (float) $decision['size'];

        // Get current market data to ensure proper distance from market
        $marketsEndpoint = app(\App\Services\IG\Endpoints\MarketsEndpoint::class);
        $marketDetails = $marketsEndpoint->get($market->epic);
        $snapshot = $marketDetails['snapshot'];

        // Get dealing rules for this specific market
        $dealingRules = $marketDetails['dealingRules'];
        $minStepDistance = $dealingRules['minStepDistance']['value'] ?? 5;

        // Determine point scale based on epic (SB vs CFD)
        // For Spread Betting (CS.D.): 1 point = 1 pip = 0.0001
        // For CFD (IX.D., etc.): 1 point = 0.00001
        $isSpreadBetting = str_starts_with($market->epic, 'CS.D.');
        $pointScale = $isSpreadBetting ? 10000 : 100000; // SB: /10000, CFD: /100000
        $pointScaleDecimal = Decimal::of($pointScale);

        $currentBidRaw = (int) ($snapshot['bid'] ?? 0);
        $currentOfferRaw = (int) ($snapshot['offer'] ?? 0);
        $currentBidDecimal = Decimal::of($currentBidRaw)->dividedBy($pointScaleDecimal, 12, RoundingMode::HALF_UP);
        $currentOfferDecimal = Decimal::of($currentOfferRaw)->dividedBy($pointScaleDecimal, 12, RoundingMode::HALF_UP);
        $currentBid = Decimal::toFloat($currentBidDecimal, 12);
        $currentOffer = Decimal::toFloat($currentOfferDecimal, 12);

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

        $safeMinDistanceDecimal = Decimal::of($safeMinDistance);
        $safeEntryDistanceDecimal = Decimal::of($safeEntryDistance);
        $entryOffset = $safeEntryDistanceDecimal->dividedBy($pointScaleDecimal, 12, RoundingMode::HALF_UP);
        $minDistanceOffset = $safeMinDistanceDecimal->dividedBy($pointScaleDecimal, 12, RoundingMode::HALF_UP);

        // Calculate entry level using correct point scale
        if ($action === 'BUY') {
            // BUY STOP must be above current offer by at least minStepDistance + buffer
            $realisticEntryDecimal = $currentOfferDecimal->plus($entryOffset);
            $orderType = 'STOP';
            // For BUY orders: stop below entry, limit above entry
            $stopLevelDecimal = $realisticEntryDecimal->minus($minDistanceOffset);
            $limitLevelDecimal = $realisticEntryDecimal->plus($minDistanceOffset);
        } else {
            // SELL STOP must be below current bid by at least minStepDistance + buffer
            $realisticEntryDecimal = $currentBidDecimal->minus($entryOffset);
            $orderType = 'STOP';
            // For SELL orders: stop above entry, limit below entry
            $stopLevelDecimal = $realisticEntryDecimal->plus($minDistanceOffset);
            $limitLevelDecimal = $realisticEntryDecimal->minus($minDistanceOffset);
        }

        // Snap all levels to proper ticks (0.0001 for EUR/USD, 0.01 for JPY pairs)
        $tickSize = PipMath::tickSize($pair);
        $tickSizeDecimal = Decimal::of($tickSize);
        $realisticEntryDecimal = self::snapToTick($realisticEntryDecimal, $tickSizeDecimal);
        $stopLevelDecimal = self::snapToTick($stopLevelDecimal, $tickSizeDecimal);
        $limitLevelDecimal = self::snapToTick($limitLevelDecimal, $tickSizeDecimal);

        $realisticEntry = Decimal::toFloat($realisticEntryDecimal, 12);
        $stopLevel = Decimal::toFloat($stopLevelDecimal, 12);
        $limitLevel = Decimal::toFloat($limitLevelDecimal, 12);

        // Convert back to raw format for IG API (multiply by pointScale)
        $rawEntry = $realisticEntryDecimal->multipliedBy($pointScaleDecimal)->toScale(0, RoundingMode::HALF_UP)->toInt();
        $rawStopLevel = $stopLevelDecimal->multipliedBy($pointScaleDecimal)->toScale(0, RoundingMode::HALF_UP)->toInt();
        $rawLimitLevel = $limitLevelDecimal->multipliedBy($pointScaleDecimal)->toScale(0, RoundingMode::HALF_UP)->toInt();

        // Final validation: ensure distances from current market are adequate
        $currentPriceDecimal = $action === 'BUY' ? $currentOfferDecimal : $currentBidDecimal;
        $entryDistanceFromMarketDecimal = $realisticEntryDecimal->minus($currentPriceDecimal)->abs()->multipliedBy($pointScaleDecimal);
        $stopDistanceFromEntryDecimal = $stopLevelDecimal->minus($realisticEntryDecimal)->abs()->multipliedBy($pointScaleDecimal);
        $limitDistanceFromEntryDecimal = $limitLevelDecimal->minus($realisticEntryDecimal)->abs()->multipliedBy($pointScaleDecimal);

        $entryDistanceFromMarket = Decimal::toFloat($entryDistanceFromMarketDecimal, 4);
        $stopDistanceFromEntry = Decimal::toFloat($stopDistanceFromEntryDecimal, 4);
        $limitDistanceFromEntry = Decimal::toFloat($limitDistanceFromEntryDecimal, 4);

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

        $sizeDecimal = Decimal::of($size);
        $minSizeDecimal = Decimal::of(0.04);
        if ($sizeDecimal->isLessThan($minSizeDecimal)) {
            $sizeDecimal = $minSizeDecimal;
        }
        $adjustedSize = Decimal::toFloat($sizeDecimal, 6);

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
        $fromDecimal = Decimal::of($from);
        $toDecimal = Decimal::of($to);
        $priceDelta = $fromDecimal->minus($toDecimal)->abs();
        $pipSizeDecimal = Decimal::of(PipMath::pipSize($pair));

        if ($pipSizeDecimal->isZero()) {
            return 0.0;
        }

        $pipsDistance = $priceDelta->dividedBy($pipSizeDecimal, 12, RoundingMode::HALF_UP);

        return Decimal::toFloat($pipsDistance->toScale(0, RoundingMode::HALF_UP), 0);
    }

    /**
     * Snap price to the nearest valid tick size
     */
    protected static function snapToTick(BigDecimal $price, BigDecimal $tickSize): BigDecimal
    {
        $steps = $price->dividedBy($tickSize, 12, RoundingMode::HALF_UP)->toScale(0, RoundingMode::HALF_UP);

        return $steps->multipliedBy($tickSize);
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
