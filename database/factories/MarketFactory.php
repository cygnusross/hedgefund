<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Market>
 */
class MarketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $currencies = [
            ['EUR', 'USD'],
            ['GBP', 'USD'],
            ['USD', 'JPY'],
            ['USD', 'CHF'],
            ['AUD', 'USD'],
            ['USD', 'CAD'],
            ['NZD', 'USD'],
        ];

        $pair = fake()->randomElement($currencies);
        $symbol = implode('/', $pair);
        $epic = 'CS.D.'.implode('', $pair).'.TODAY.IP';

        return [
            'name' => $symbol,
            'symbol' => $symbol,
            'epic' => $epic,
            'currencies' => $pair,
            'is_active' => true,
        ];
    }
}
