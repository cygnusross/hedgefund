<?php

namespace Tests\Traits;

use App\Models\CalendarEvent;
use App\Models\Market;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

trait CalendarCsvTestHelpers
{
    /**
     * Create a CSV file in fake storage for testing.
     */
    protected function createCsvFile(string $filename, array $headers, array $rows): void
    {
        $content = implode(',', $headers)."\n";
        foreach ($rows as $row) {
            $content .= implode(',', $row)."\n";
        }

        Storage::disk('local')->put("calendar_csv/{$filename}", trim($content));
    }

    /**
     * Create a simple calendar CSV with standard headers.
     */
    protected function createSimpleCalendarCsv(string $filename, array $events): void
    {
        $headers = ['title', 'currency', 'impact', 'date', 'source'];
        $this->createCsvFile($filename, $headers, $events);
    }

    /**
     * Get the path to the calendar CSV directory.
     */
    protected function getCalendarCsvPath(): string
    {
        return Storage::disk('local')->path('calendar_csv');
    }

    /**
     * Create and get importer instance.
     */
    protected function getCalendarCsvImporter(): \App\Application\Calendar\CalendarCsvImporter
    {
        return app(\App\Application\Calendar\CalendarCsvImporter::class);
    }

    /**
     * Standard setup for most calendar CSV tests.
     */
    protected function setupCalendarCsvTest(): void
    {
        Storage::fake('local');
    }

    /**
     * Create test markets for ALL currency expansion tests.
     */
    protected function createTestMarkets(): void
    {
        Market::create(['name' => 'EURUSD', 'symbol' => 'EUR/USD', 'epic' => 'EURUSD', 'currencies' => ['EUR', 'USD'], 'is_active' => 1]);
        Market::create(['name' => 'GBPUSD', 'symbol' => 'GBP/USD', 'epic' => 'GBPUSD', 'currencies' => ['GBP', 'USD'], 'is_active' => 1]);
    }

    /**
     * Create calendar event for testing with proper hash.
     */
    protected function createCalendarEvent(array $attributes): CalendarEvent
    {
        $title = $attributes['title'] ?? 'Test Event';
        $currency = $attributes['currency'] ?? 'USD';
        $eventTime = isset($attributes['event_time_utc'])
            ? new \DateTimeImmutable($attributes['event_time_utc'], new \DateTimeZone('UTC'))
            : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $defaults = [
            'title' => $title,
            'currency' => $currency,
            'impact' => 'High',
            'event_time_utc' => $eventTime->format(DATE_ATOM),
            'source' => 'test',
            'hash' => CalendarEvent::makeHash($title, $currency, $eventTime),
        ];

        return CalendarEvent::create(array_merge($defaults, $attributes));
    }

    /**
     * Assert calendar event has correct UTC time formatting.
     */
    protected function assertCalendarEventTimeUtc(CalendarEvent $event, string $expectedUtcTime): void
    {
        $expected = CarbonImmutable::parse($expectedUtcTime)->setTimezone('UTC')->format(DATE_ATOM);
        expect($event->event_time_utc->format(DATE_ATOM))->toBe($expected);
    }
}
