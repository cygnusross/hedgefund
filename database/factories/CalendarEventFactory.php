<?php

namespace Database\Factories;

use App\Models\CalendarEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class CalendarEventFactory extends Factory
{
    protected $model = CalendarEvent::class;

    public function definition(): array
    {
        $title = $this->faker->sentence(3);
        $currency = $this->faker->randomElement(['USD', 'EUR', 'GBP', 'JPY']);
        $eventTime = Carbon::now('UTC')->addHours($this->faker->numberBetween(1, 72));

        return [
            'title' => $title,
            'currency' => $currency,
            'impact' => $this->faker->randomElement(['High', 'Medium', 'Low']),
            'event_time_utc' => $eventTime,
            'hash' => CalendarEvent::makeHash($title, $currency, new \DateTimeImmutable($eventTime->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'))),
            'created_at' => Carbon::now('UTC'),
            'updated_at' => Carbon::now('UTC'),
        ];
    }
}
