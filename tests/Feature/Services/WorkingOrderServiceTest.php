<?php

declare(strict_types=1);

use App\Models\Market;
use App\Models\Order;
use App\Services\IG\Client;
use App\Services\IG\Endpoints\WorkingOrdersOtcEndpoint;
use App\Services\IG\WorkingOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create test markets
    Market::create([
        'name' => 'EUR/USD',
        'symbol' => 'EUR/USD',
        'epic' => 'CS.D.EURUSD.TODAY.IP', // Updated to current epic
        'currencies' => json_encode(['EUR', 'USD']),
        'is_active' => true,
    ]);

    Market::create([
        'name' => 'GBP/USD',
        'symbol' => 'GBP/USD',
        'epic' => 'CS.D.GBPUSD.MINI.IP',
        'currencies' => json_encode(['GBP', 'USD']),
        'is_active' => true,
    ]);

    $this->igClient = Mockery::mock(Client::class);
    $this->workingOrdersEndpoint = Mockery::mock(WorkingOrdersOtcEndpoint::class);
    $this->service = new WorkingOrderService($this->igClient, $this->workingOrdersEndpoint);
});

test('creates working order from decision successfully', function () {
    $decision = [
        'action' => 'BUY',
        'confidence' => 0.6,
        'size' => 0.5,
        'entry' => 1.1765,
        'sl' => 1.1750,
        'tp' => 1.1780,
    ];

    $pair = 'EUR/USD';
    $expectedDealRef = 'test-deal-ref';

    // Mock the endpoint response
    $this->workingOrdersEndpoint
        ->shouldReceive('create')
        ->once()
        ->withArgs(function ($payload) {
            expect($payload['direction'])->toBe('BUY');
            expect($payload['epic'])->toBe('CS.D.EURUSD.TODAY.IP'); // Updated epic
            expect($payload)->toHaveKey('stopDistance'); // Now uses distances instead of levels
            expect($payload)->toHaveKey('limitDistance');

            return true;
        })
        ->andReturn(['dealReference' => $expectedDealRef]);

    $order = $this->service->createWorkingOrderFromDecision($decision, $pair);

    expect($order)->toBeInstanceOf(Order::class);
    expect($order->deal_reference)->toBe($expectedDealRef);
    expect($order->direction)->toBe('BUY');
    expect($order->status)->toBe('PENDING');
    // Level is now calculated based on market conditions, so just check it exists and is reasonable
    expect($order->level)->toBeNumeric();
    expect((float) $order->level)->toBeGreaterThan(1.17);
});

test('handles failed working order creation gracefully', function () {
    $decision = [
        'action' => 'BUY',
        'confidence' => 0.6,
        'size' => 0.5,
        'entry' => 1.1765,
        'sl' => 1.1750,
        'tp' => 1.1780,
    ];

    $pair = 'EUR/USD';

    // Mock endpoint to throw exception
    $this->workingOrdersEndpoint
        ->shouldReceive('create')
        ->once()
        ->andThrow(new \Exception('IG API Error'));

    $order = $this->service->createWorkingOrderFromDecision($decision, $pair);

    expect($order)->toBeNull();
});

test('creates working order with raw data successfully', function () {
    $orderData = [
        'currencyCode' => 'GBP',
        'direction' => 'SELL',
        'epic' => 'CS.D.GBPUSD.MINI.IP',
        'guaranteedStop' => false,
        'level' => 1.2500,
        'size' => 1.0,
        'stopDistance' => 15,
        'limitDistance' => 30,
        'timeInForce' => 'GOOD_TILL_CANCELLED',
        'type' => 'LIMIT',
    ];

    $expectedDealRef = 'test-deal-ref-2';

    $this->workingOrdersEndpoint
        ->shouldReceive('create')
        ->once()
        ->withArgs(function ($payload) use ($orderData) {
            // The service adds dealReference, so we need to account for that
            expect($payload['currencyCode'])->toBe($orderData['currencyCode']);
            expect($payload['direction'])->toBe($orderData['direction']);
            expect($payload['epic'])->toBe($orderData['epic']);
            expect(isset($payload['dealReference']))->toBeTrue();

            return true;
        })
        ->andReturn(['dealReference' => $expectedDealRef]);

    $order = $this->service->createWorkingOrder($orderData);

    expect($order)->toBeInstanceOf(Order::class);
    expect($order->deal_reference)->toBe($expectedDealRef);
    expect($order->direction)->toBe('SELL');
    expect($order->epic)->toBe('CS.D.GBPUSD.MINI.IP');
    expect((float) $order->level)->toBe(1.2500);
});

test('validates required fields before creating order', function () {
    $invalidOrderData = [
        'currencyCode' => 'GBP',
        // Missing required fields
    ];

    expect(fn() => $this->service->createWorkingOrder($invalidOrderData))
        ->toThrow(\InvalidArgumentException::class, 'Missing required field');
});

test('validates mutual exclusivity constraints', function () {
    $invalidOrderData = [
        'currencyCode' => 'GBP',
        'direction' => 'BUY',
        'epic' => 'CS.D.EURUSD.MINI.IP',
        'guaranteedStop' => false,
        'level' => 1.1765,
        'size' => 1.0,
        'limitLevel' => 1.1780,
        'limitDistance' => 15, // Cannot have both
    ];

    expect(fn() => $this->service->createWorkingOrder($invalidOrderData))
        ->toThrow(\InvalidArgumentException::class, 'Set only one of limitLevel or limitDistance');
});

test('gets pending orders', function () {
    // Create some test orders
    Order::factory()->pending()->create();
    Order::factory()->filled()->create();
    Order::factory()->pending()->create();

    $pendingOrders = $this->service->getPendingOrders();

    expect($pendingOrders)->toHaveCount(2);
    expect($pendingOrders->every(fn($order) => $order->status === 'PENDING'))->toBeTrue();
});

test('updates order status', function () {
    $order = Order::factory()->pending()->create(['deal_reference' => 'test-ref']);

    $result = $this->service->updateOrderStatus('test-ref', 'FILLED');

    expect($result)->toBeTrue();
    expect($order->fresh()->status)->toBe('FILLED');
});

test('cancels working order', function () {
    $order = Order::factory()->pending()->create();

    $result = $this->service->cancelWorkingOrder($order);

    expect($result)->toBeTrue();
    expect($order->fresh()->status)->toBe('CANCELLED');
});
