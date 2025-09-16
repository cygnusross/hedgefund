<?php

namespace App\Services\IG;

use App\Domain\Execution\DecisionToIgOrderConverter;
use App\Models\Order;
use App\Services\IG\Endpoints\WorkingOrdersOtcEndpoint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WorkingOrderService
{
    public function __construct(
        private Client $igClient,
        private WorkingOrdersOtcEndpoint $workingOrdersEndpoint
    ) {}

    /**
     * Create a working order from a trading decision array
     */
    public function createWorkingOrderFromDecision(array $decision, string $pair): ?Order
    {
        try {
            // Convert decision to IG format using existing converter
            $payload = DecisionToIgOrderConverter::convert($decision, $pair);

            // Add deal reference if not present
            if (empty($payload['dealReference'])) {
                $payload['dealReference'] = 'HF_'.Str::upper(Str::random(8)).'_'.time();
            }

            // Remove expiry field if it's null or empty for spread betting accounts
            if (array_key_exists('expiry', $payload) && (is_null($payload['expiry']) || $payload['expiry'] === '')) {
                unset($payload['expiry']);
            }

            $response = $this->workingOrdersEndpoint->create($payload);

            if ($response && isset($response['dealReference'])) {
                return $this->saveOrderFromDecision($decision, $pair, $response['dealReference'], $payload);
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Failed to create working order from decision', [
                'error' => $e->getMessage(),
                'decision' => $decision,
                'pair' => $pair,
            ]);

            return null;
        }
    }

    /**
     * Save order to database from decision data
     */
    private function saveOrderFromDecision(array $decision, string $pair, string $dealReference, array $payload): Order
    {
        return Order::create([
            'deal_reference' => $dealReference,
            'currency_code' => $payload['currencyCode'] ?? 'GBP',
            'direction' => $payload['direction'],
            'epic' => $payload['epic'],
            'expiry' => $payload['expiry'] ?? '-',
            'force_open' => $payload['forceOpen'] ?? false,
            'good_till_date' => $payload['goodTillDate'] ?? null,
            'guaranteed_stop' => $payload['guaranteedStop'] ?? false,
            'level' => $payload['level'],
            'limit_distance' => $payload['limitDistance'] ?? null,
            'limit_level' => $payload['limitLevel'] ?? null,
            'size' => $payload['size'],
            'stop_distance' => $payload['stopDistance'] ?? null,
            'stop_level' => $payload['stopLevel'] ?? null,
            'time_in_force' => $payload['timeInForce'] ?? 'GOOD_TILL_CANCELLED',
            'type' => $payload['type'] ?? 'LIMIT',
            'status' => 'PENDING',
        ]);
    }

    /**
     * Create a working order via IG API and store it in the database.
     *
     * @param  array  $orderData  Order parameters matching IG API specification
     * @return Order Created order model
     *
     * @throws \Exception
     */
    public function createWorkingOrder(array $orderData): Order
    {
        // Generate deal reference if not provided
        if (empty($orderData['dealReference'])) {
            $orderData['dealReference'] = 'HF_'.Str::upper(Str::random(8)).'_'.time();
        }

        // Validate required fields match Order model structure
        $this->validateOrderData($orderData);

        try {
            // Create the order via IG API using existing endpoint
            $response = $this->workingOrdersEndpoint->create($orderData);

            // Store in database
            $order = Order::create([
                'deal_reference' => $response['dealReference'] ?? $orderData['dealReference'],
                'currency_code' => $orderData['currencyCode'],
                'direction' => $orderData['direction'],
                'epic' => $orderData['epic'],
                'expiry' => $orderData['expiry'] ?? '-',
                'force_open' => $orderData['forceOpen'] ?? false,
                'good_till_date' => $orderData['goodTillDate'] ?? null,
                'guaranteed_stop' => $orderData['guaranteedStop'] ?? false,
                'level' => $orderData['level'],
                'limit_distance' => $orderData['limitDistance'] ?? null,
                'limit_level' => $orderData['limitLevel'] ?? null,
                'size' => $orderData['size'],
                'stop_distance' => $orderData['stopDistance'] ?? null,
                'stop_level' => $orderData['stopLevel'] ?? null,
                'time_in_force' => $orderData['timeInForce'] ?? 'GOOD_TILL_CANCELLED',
                'type' => $orderData['type'] ?? 'LIMIT',
                'status' => 'PENDING',
            ]);

            Log::info('Working order created successfully', [
                'deal_reference' => $order->deal_reference,
                'epic' => $order->epic,
                'direction' => $order->direction,
                'size' => $order->size,
                'level' => $order->level,
            ]);

            return $order;
        } catch (\Exception $e) {
            Log::error('Failed to create working order', [
                'error' => $e->getMessage(),
                'order_data' => $orderData,
            ]);

            throw $e;
        }
    }

    /**
     * Convert decision engine output to IG working order format.
     *
     * @param  array  $decision  Decision engine output
     * @param  array  $market  Market context data
     * @return array IG working order payload
     */
    public function convertDecisionToWorkingOrder(array $decision, array $market): array
    {
        $epic = $this->getEpicFromMarketId($market['market_id'] ?? '');

        return [
            'currencyCode' => $this->extractBaseCurrency($market['market_id'] ?? ''),
            'direction' => strtoupper($decision['action']),
            'epic' => $epic,
            // Remove expiry for spot FX markets - IG API rejects '-' for working orders
            'guaranteedStop' => false,
            'level' => $decision['entry'],
            'size' => $decision['size'],
            'stopDistance' => $this->calculateStopDistance($decision),
            'limitDistance' => $this->calculateLimitDistance($decision),
            'timeInForce' => 'GOOD_TILL_CANCELLED',
            'type' => 'LIMIT',
        ];
    }

    /**
     * Validate order data before submission.
     *
     * @throws \InvalidArgumentException
     */
    private function validateOrderData(array $orderData): void
    {
        $required = ['currencyCode', 'direction', 'epic', 'guaranteedStop', 'level', 'size'];

        foreach ($required as $field) {
            if (! isset($orderData[$field]) || $orderData[$field] === null || $orderData[$field] === '') {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        // Validate enums
        if (! in_array($orderData['direction'], ['BUY', 'SELL'])) {
            throw new \InvalidArgumentException('Direction must be BUY or SELL');
        }

        // Validate mutual exclusivity constraints
        if (! empty($orderData['limitLevel']) && ! empty($orderData['limitDistance'])) {
            throw new \InvalidArgumentException('Set only one of limitLevel or limitDistance');
        }

        if (! empty($orderData['stopLevel']) && ! empty($orderData['stopDistance'])) {
            throw new \InvalidArgumentException('Set only one of stopLevel or stopDistance');
        }

        // Validate guaranteed stop constraints
        if (! empty($orderData['guaranteedStop']) && $orderData['guaranteedStop'] === true) {
            if (empty($orderData['stopDistance']) || ! empty($orderData['stopLevel'])) {
                throw new \InvalidArgumentException('If guaranteedStop is true, set only stopDistance');
            }
        }

        // Validate timeInForce constraints
        if (! empty($orderData['timeInForce']) && $orderData['timeInForce'] === 'GOOD_TILL_DATE') {
            if (empty($orderData['goodTillDate'])) {
                throw new \InvalidArgumentException('If timeInForce equals GOOD_TILL_DATE, then set goodTillDate');
            }
        }

        // Validate size precision (max 12 decimal places)
        if (isset($orderData['size']) && is_numeric($orderData['size'])) {
            $sizeStr = (string) $orderData['size'];
            $decimalPos = strpos($sizeStr, '.');
            if ($decimalPos !== false) {
                $decimalPlaces = strlen(substr($sizeStr, $decimalPos + 1));
                if ($decimalPlaces > 12) {
                    throw new \InvalidArgumentException('Size precision cannot be more than 12 decimal places');
                }
            }
        }

        // Validate dealReference pattern
        if (! empty($orderData['dealReference'])) {
            if (! preg_match('/^[A-Za-z0-9_\-]{1,30}$/', $orderData['dealReference'])) {
                throw new \InvalidArgumentException('dealReference must match pattern [A-Za-z0-9_\-]{1,30}');
            }
        }

        // Validate expiry pattern
        if (! empty($orderData['expiry'])) {
            if (! preg_match('/^((\d{2}-)?[A-Z]{3}-\d{2}|-|DFB)$/', $orderData['expiry'])) {
                throw new \InvalidArgumentException('expiry must match pattern (\d{2}-)?[A-Z]{3}-\d{2}|-|DFB');
            }
        }
    }

    /**
     * Get IG epic from market ID.
     */
    private function getEpicFromMarketId(string $marketId): string
    {
        // Map common market IDs to IG epics
        $epicMap = [
            'EURUSD' => 'CS.D.EURUSD.MINI.IP',
            'GBPUSD' => 'CS.D.GBPUSD.MINI.IP',
            'USDJPY' => 'CS.D.USDJPY.MINI.IP',
            'EURGBP' => 'CS.D.EURGBP.MINI.IP',
        ];

        return $epicMap[$marketId] ?? throw new \InvalidArgumentException("Unknown market ID: {$marketId}");
    }

    /**
     * Extract base currency from market ID.
     */
    private function extractBaseCurrency(string $marketId): string
    {
        // Extract first 3 characters as base currency
        return strtoupper(substr($marketId, 0, 3));
    }

    /**
     * Calculate stop distance in points from decision data.
     */
    private function calculateStopDistance(array $decision): ?float
    {
        if (empty($decision['entry']) || empty($decision['sl'])) {
            return null;
        }

        $entry = (float) $decision['entry'];
        $sl = (float) $decision['sl'];

        return abs($entry - $sl) * 10000; // Convert to points (pips * 10)
    }

    /**
     * Calculate limit distance in points from decision data.
     */
    private function calculateLimitDistance(array $decision): ?float
    {
        if (empty($decision['entry']) || empty($decision['tp'])) {
            return null;
        }

        $entry = (float) $decision['entry'];
        $tp = (float) $decision['tp'];

        return abs($tp - $entry) * 10000; // Convert to points (pips * 10)
    }

    /**
     * Get all pending orders.
     */
    public function getPendingOrders(): \Illuminate\Database\Eloquent\Collection
    {
        return Order::where('status', 'PENDING')->get();
    }

    /**
     * Update order status.
     */
    public function updateOrderStatus(string $dealReference, string $status): bool
    {
        return Order::where('deal_reference', $dealReference)
            ->update(['status' => $status]) > 0;
    }

    /**
     * Cancel a working order.
     */
    public function cancelWorkingOrder(Order $order): bool
    {
        try {
            // TODO: Implement IG API cancel endpoint when needed
            // For now, just mark as cancelled in database
            $order->update(['status' => 'CANCELLED']);

            Log::info('Working order cancelled', [
                'deal_reference' => $order->deal_reference,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to cancel working order', [
                'deal_reference' => $order->deal_reference,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
