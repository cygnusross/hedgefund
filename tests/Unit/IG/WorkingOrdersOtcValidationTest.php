<?php

use App\Services\IG\DTO\WorkingOrderRequest;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class);

it('validates a correct payload', function () {
    $payload = [
        'currencyCode' => 'GBP',
        'direction' => 'BUY',
        'epic' => 'EPIC',
        'expiry' => '-',
        'guaranteedStop' => false,
        'level' => 1.23,
        'size' => 1,
    ];

    $normalized = WorkingOrderRequest::validate($payload);

    expect(isset($normalized['request']))->toBeTrue();
});

it('fails when required fields missing', function () {
    $this->expectException(ValidationException::class);
    WorkingOrderRequest::validate([]);
});

it('fails mutual exclusivity for limit', function () {
    $this->expectException(ValidationException::class);
    WorkingOrderRequest::validate([
        'currencyCode' => 'GBP',
        'epic' => 'EPIC',
        'expiry' => '-',
        'guaranteedStop' => false,
        'level' => 1.23,
        'size' => 1,
        'limitLevel' => 10,
        'limitDistance' => 5,
    ]);
});

it('fails mutual exclusivity for stop', function () {
    $this->expectException(ValidationException::class);
    WorkingOrderRequest::validate([
        'currencyCode' => 'GBP',
        'epic' => 'EPIC',
        'expiry' => '-',
        'guaranteedStop' => false,
        'level' => 1.23,
        'size' => 1,
        'stopLevel' => 10,
        'stopDistance' => 5,
    ]);
});

it('fails when guaranteedStop true but stopLevel set', function () {
    $this->expectException(ValidationException::class);
    WorkingOrderRequest::validate([
        'currencyCode' => 'GBP',
        'epic' => 'EPIC',
        'expiry' => '-',
        'guaranteedStop' => true,
        'level' => 1.23,
        'size' => 1,
        'stopLevel' => 10,
    ]);
});

it('fails when size precision is too large', function () {
    $this->expectException(ValidationException::class);
    WorkingOrderRequest::validate([
        'currencyCode' => 'GBP',
        'epic' => 'EPIC',
        'expiry' => '-',
        'guaranteedStop' => false,
        'level' => 1.23,
        'size' => '1.'.str_repeat('1', 13),
    ]);
});

it('fails when timeInForce is GOOD_TILL_DATE without goodTillDate', function () {
    $this->expectException(ValidationException::class);
    WorkingOrderRequest::validate([
        'currencyCode' => 'GBP',
        'epic' => 'EPIC',
        'expiry' => '-',
        'guaranteedStop' => false,
        'level' => 1.23,
        'size' => 1,
        'timeInForce' => 'GOOD_TILL_DATE',
    ]);
});
