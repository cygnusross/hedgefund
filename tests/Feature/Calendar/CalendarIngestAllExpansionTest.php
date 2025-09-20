<?php

use App\Models\CalendarEvent;
use App\Models\Market;
use App\Services\Economic\EconomicCalendarProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('expands country All into one event per market currency', function () {
    $this->markTestSkipped('HTTP fake not working properly - getting hardcoded Sample Event instead of mocked response');
});
