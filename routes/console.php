<?php

use Illuminate\Support\Facades\Artisan;

if (isset($schedule)) {
    // Economic calendar refresh - weekdays every 20 minutes between 07:00 and 22:00 London time
    $schedule->command('economic:refresh-calendar --force')
        ->weekdays()
        ->between('07:00', '22:00')
        ->everyTwentyMinutes()
        ->timezone('Europe/London');

    $schedule->command('economic:refresh-calendar --force')
        ->weekdays()
        ->dailyAt('06:30')
        ->timezone('Europe/London');

    $schedule->command('economic:refresh-calendar --force')
        ->saturdays()
        ->sundays()
        ->dailyAt('12:00')
        ->timezone('Europe/London');

    // Candle refresh schedule - All active markets
    $schedule->command('candles:refresh --interval=5min')
        ->weekdays()
        ->between('07:00', '22:00')
        ->everyFiveMinutes()
        ->timezone('Europe/London');

    $schedule->command('candles:refresh --interval=30min')
        ->weekdays()
        ->between('07:00', '22:00')
        ->everyThirtyMinutes()
        ->timezone('Europe/London');

    // Batch decision analysis - runs every hour at 15 minutes past
    $schedule->command('decision:batch --account="Primary Trading Sleeve"')
        ->weekdays()
        ->hourlyAt(15)
        ->between('08:00', '17:00')
        ->withoutOverlapping()
        ->onOneServer()
        ->timezone('Europe/London');

    // Spread recording - hourly during trading hours
    $schedule->command('spreads:record-current')
        ->hourly()
        ->weekdays()
        ->between('07:00', '22:00')
        ->timezone('Europe/London');

    $schedule->command('rules:calibrate')
        ->sundays()
        ->at('22:00')
        ->timezone('Europe/London')
        ->withoutOverlapping();
}

Artisan::command('backtest:run {--pair=*} {--start=} {--end=}', function () {
    $options = array_filter([
        '--pair' => $this->option('pair'),
        '--start' => $this->option('start'),
        '--end' => $this->option('end'),
    ], fn ($value) => $value !== null && $value !== []);

    $this->call(\App\Console\Commands\BacktestRun::class, $options);
})->purpose('Run decision engine backtest using stored market data.');
