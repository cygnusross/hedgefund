<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Candle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Candle>
 */
class CandleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Candle::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $open = $this->faker->randomFloat(5, 1.0000, 2.0000);
        $range = $this->faker->randomFloat(5, 0.0010, 0.0050); // 10-50 pip range

        $high = $open + ($range * $this->faker->randomFloat(2, 0.3, 0.8));
        $low = $open - ($range * $this->faker->randomFloat(2, 0.3, 0.8));
        $close = $this->faker->randomFloat(5, $low, $high);

        return [
            'pair' => $this->faker->randomElement(['EURUSD', 'GBPUSD', 'USDJPY', 'AUDUSD']),
            'interval' => $this->faker->randomElement(['1H', '4H', '1D']),
            'timestamp' => CarbonImmutable::now()->subHours($this->faker->numberBetween(1, 168)),
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => $this->faker->numberBetween(100, 5000),
            'provider' => $this->faker->randomElement(['twelvedata', 'ig', 'database']),
        ];
    }

    /**
     * Create candles for EURUSD
     */
    public function eurusd(): static
    {
        return $this->state(fn (array $attributes) => [
            'pair' => 'EURUSD',
        ]);
    }

    /**
     * Create candles for GBPUSD
     */
    public function gbpusd(): static
    {
        return $this->state(fn (array $attributes) => [
            'pair' => 'GBPUSD',
        ]);
    }

    /**
     * Create hourly candles
     */
    public function hourly(): static
    {
        return $this->state(fn (array $attributes) => [
            'interval' => '1H',
        ]);
    }

    /**
     * Create candles with specific timestamp
     */
    public function at(CarbonImmutable $timestamp): static
    {
        return $this->state(fn (array $attributes) => [
            'timestamp' => $timestamp,
        ]);
    }

    /**
     * Create bullish candle (close > open)
     */
    public function bullish(): static
    {
        return $this->state(function (array $attributes) {
            $open = $this->faker->randomFloat(5, 1.0000, 2.0000);
            $close = $open + $this->faker->randomFloat(5, 0.0005, 0.0030);
            $high = max($open, $close) + $this->faker->randomFloat(5, 0.0002, 0.0010);
            $low = min($open, $close) - $this->faker->randomFloat(5, 0.0002, 0.0010);

            return [
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
            ];
        });
    }

    /**
     * Create bearish candle (close < open)
     */
    public function bearish(): static
    {
        return $this->state(function (array $attributes) {
            $open = $this->faker->randomFloat(5, 1.0000, 2.0000);
            $close = $open - $this->faker->randomFloat(5, 0.0005, 0.0030);
            $high = max($open, $close) + $this->faker->randomFloat(5, 0.0002, 0.0010);
            $low = min($open, $close) - $this->faker->randomFloat(5, 0.0002, 0.0010);

            return [
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
            ];
        });
    }
}
