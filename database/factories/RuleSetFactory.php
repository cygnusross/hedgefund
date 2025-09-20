<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RuleSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RuleSet>
 */
class RuleSetFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = RuleSet::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tag' => 'test-'.$this->faker->dateTimeThisYear()->format('Y-m-d').'-'.$this->faker->word,
            'period_start' => $this->faker->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'period_end' => $this->faker->dateTimeBetween('now', '+1 month')->format('Y-m-d'),
            'base_rules' => [
                'gates' => [
                    'adx_min' => $this->faker->numberBetween(20, 30),
                    'sentiment' => ['mode' => $this->faker->randomElement(['contrarian', 'confirming'])],
                ],
                'execution' => [
                    'rr' => $this->faker->randomFloat(2, 1.5, 3.0),
                    'sl_atr_mult' => $this->faker->randomFloat(1, 1.5, 3.0),
                    'tp_atr_mult' => $this->faker->randomFloat(1, 3.0, 9.0),
                ],
                'risk' => [
                    'per_trade_pct' => ['default' => $this->faker->randomFloat(2, 0.5, 2.0)],
                ],
            ],
            'market_overrides' => [],
            'emergency_overrides' => [],
            'metrics' => [
                'total_pnl' => $this->faker->randomFloat(2, -100, 200),
                'win_rate' => $this->faker->randomFloat(3, 0.4, 0.8),
                'max_drawdown_pct' => $this->faker->randomFloat(2, 3.0, 15.0),
                'trades_total' => $this->faker->numberBetween(10, 100),
                'sharpe_ratio' => $this->faker->randomFloat(2, -0.5, 2.0),
                'profit_factor' => $this->faker->randomFloat(2, 0.5, 3.0),
            ],
            'provenance' => [
                'created_by' => 'factory',
                'version' => '1.0.0',
            ],
            'is_active' => false,
        ];
    }

    /**
     * Create an active rule set
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    /**
     * Create a shadow mode rule set (using provenance to track)
     */
    public function shadowMode(): static
    {
        return $this->state(fn (array $attributes) => [
            'provenance' => array_merge($attributes['provenance'] ?? [], [
                'shadow_mode' => true,
            ]),
        ]);
    }

    /**
     * Create a high-performance rule set
     */
    public function highPerformance(): static
    {
        return $this->state(fn (array $attributes) => [
            'metrics' => [
                'total_pnl' => $this->faker->randomFloat(2, 150, 300),
                'win_rate' => $this->faker->randomFloat(3, 0.65, 0.85),
                'max_drawdown_pct' => $this->faker->randomFloat(2, 3.0, 8.0),
                'trades_total' => $this->faker->numberBetween(50, 150),
                'sharpe_ratio' => $this->faker->randomFloat(2, 1.2, 2.5),
                'profit_factor' => $this->faker->randomFloat(2, 1.8, 3.5),
            ],
        ]);
    }

    /**
     * Create a poor-performance rule set
     */
    public function poorPerformance(): static
    {
        return $this->state(fn (array $attributes) => [
            'metrics' => [
                'total_pnl' => $this->faker->randomFloat(2, -150, -20),
                'win_rate' => $this->faker->randomFloat(3, 0.25, 0.45),
                'max_drawdown_pct' => $this->faker->randomFloat(2, 15.0, 25.0),
                'trades_total' => $this->faker->numberBetween(5, 30),
                'sharpe_ratio' => $this->faker->randomFloat(2, -1.5, -0.2),
                'profit_factor' => $this->faker->randomFloat(2, 0.3, 0.8),
            ],
        ]);
    }
}
