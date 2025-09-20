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

        // Allow symbol override to determine epic deterministically
        // epic should be deterministically derived from the final symbol
        $epicClosure = function (array $attributes) use ($symbol) {
            $sym = $attributes['symbol'] ?? $symbol;
            $parts = explode('/', $sym);
            $joined = implode('', $parts);

            return 'CS.D.'.$joined.'.TODAY.IP';
        };

        return [
            'symbol' => function (array $attributes) use ($symbol) {
                return $attributes['symbol'] ?? $symbol;
            },
            'name' => function (array $attributes) use ($symbol) {
                return $attributes['symbol'] ?? $symbol;
            },
            'epic' => function (array $attributes) use ($epicClosure) {
                return $epicClosure($attributes);
            },
            'currencies' => function (array $attributes) use ($symbol) {
                $sym = $attributes['symbol'] ?? $symbol;
                $parts = explode('/', $sym);

                return $parts;
            },
            'is_active' => true,
        ];
    }
}
