<?php

if (isset($schedule)) {
    $schedule->command('economic:refresh-calendar --force')
        ->weekdays()
        ->between('07:00', '22:00')
        ->everyTwentyMinutes();

    $schedule->command('economic:refresh-calendar --force')
        ->weekdays()
        ->dailyAt('06:30');

    $schedule->command('economic:refresh-calendar --force')
        ->saturdays()
        ->sundays()
        ->dailyAt('12:00');

    // Candle refresh schedule
    $schedule->command('candles:refresh EURUSD --interval=5min')
        ->weekdays()
        ->between('07:00', '22:00')
        ->everyFiveMinutes()
        ->timezone('Europe/London');

    $schedule->command('candles:refresh EURUSD --interval=30min')
        ->weekdays()
        ->between('07:00', '22:00')
        ->everyThirtyMinutes()
        ->timezone('Europe/London');

    // Scheduled ingestion of news stats into DB.
    $schedule->command('news:ingest')
        ->weekdays()
        ->between('06:00', '22:00')
        ->everyFifteenMinutes()
        ->withoutOverlapping()
        ->onOneServer()
        ->timezone('Europe/London');

    // Economic calendar refresh - weekdays every 20 minutes between 07:00 and 22:00 London time
    $schedule->command('calendar:refresh --force')
        ->weekdays()
        ->between('07:00', '22:00')
        ->everyTwentyMinutes()
        ->timezone('Europe/London');
}
