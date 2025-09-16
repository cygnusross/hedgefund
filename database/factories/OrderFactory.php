<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deal_reference' => 'TEST_'.$this->faker->unique()->randomNumber(8),
            'currency_code' => $this->faker->randomElement(['GBP', 'USD', 'EUR']),
            'direction' => $this->faker->randomElement(['BUY', 'SELL']),
            'epic' => 'CS.D.'.$this->faker->randomElement(['EURUSD', 'GBPUSD', 'USDJPY']).'.MINI.IP',
            'expiry' => '-',
            'force_open' => $this->faker->boolean(20), // 20% chance of true
            'good_till_date' => null,
            'guaranteed_stop' => $this->faker->boolean(10), // 10% chance of true
            'level' => $this->faker->randomFloat(5, 0.5, 2.0),
            'limit_distance' => $this->faker->randomFloat(1, 10, 100),
            'limit_level' => null,
            'size' => $this->faker->randomFloat(2, 0.1, 10.0),
            'stop_distance' => $this->faker->randomFloat(1, 5, 50),
            'stop_level' => null,
            'time_in_force' => 'GOOD_TILL_CANCELLED',
            'type' => $this->faker->randomElement(['LIMIT', 'STOP']),
            'status' => $this->faker->randomElement(['PENDING', 'FILLED', 'CANCELLED', 'REJECTED']),
        ];
    }

    /**
     * Indicate that the order is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'PENDING',
        ]);
    }

    /**
     * Indicate that the order is filled.
     */
    public function filled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'FILLED',
        ]);
    }

    /**
     * Indicate that the order is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'CANCELLED',
        ]);
    }
}
