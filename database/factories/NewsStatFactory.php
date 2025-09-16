<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NewsStat>
 */
class NewsStatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pair_norm' => fake()->randomElement(['EUR-USD', 'GBP-USD', 'USD-JPY', 'AUD-USD', 'USD-CAD']),
            'stat_date' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'pos' => fake()->numberBetween(0, 100),
            'neg' => fake()->numberBetween(0, 100),
            'neu' => fake()->numberBetween(0, 100),
            'raw_score' => fake()->randomFloat(2, -1, 1),
            'strength' => fake()->randomFloat(2, 0, 1),
            'source' => 'forexnewsapi',
            'fetched_at' => now(),
            'payload' => null, // Default to null so fromModel uses model attributes
        ];
    }
}
