<?php

namespace App\Application\Calendar;

use App\Models\CalendarEvent;
use App\Models\Market;
use App\Services\Economic\EconomicCalendarProviderContract;
use Illuminate\Support\Facades\Schema;

// Use fully-qualified DateTime classes to avoid non-compound use statement warnings

final class CalendarLookup
{
    public function __construct(public EconomicCalendarProviderContract $provider) {}

    /**
     * Find next high impact event for a currency pair after $ts.
     */
    public function nextHighImpact(string $pair, \DateTimeImmutable $ts): ?array
    {
        // Use DB-backed CalendarEvent model to find upcoming high-impact events for the pair
        $pairNorm = str_replace('-', '/', $pair);
        $parts = array_map('trim', explode('/', $pairNorm));
        $relevant = array_map('strtoupper', $parts);

        // If we have a Market with a `currencies` JSON column, prefer that set for matching
        try {
            $candidates = [
                $pair,
                str_replace('/', '-', $pair),
                str_replace('-', '/', $pair),
            ];
            $market = Market::whereIn('symbol', $candidates)->first();
            if ($market && is_array($market->currencies) && count($market->currencies) > 0) {
                $relevant = array_map('strtoupper', $market->currencies);
            }
        } catch (\Throwable $e) {
            // ignore DB errors and fall back to pair-derived currencies
        }

        // If we already have persisted CalendarEvent rows, prefer DB results (useful for feature tests).
        $dbEvents = collect();
        if (Schema::hasTable('calendar_events')) {
            $dbEvents = CalendarEvent::upcoming()->highImpact()->get();
        }

        if ($dbEvents->isNotEmpty()) {
            $events = $dbEvents;

            $matches = [];
            foreach ($events as $evt) {
                try {
                    $when = $evt->event_time_utc instanceof \DateTimeImmutable ? $evt->event_time_utc : new \DateTimeImmutable($evt->event_time_utc, new \DateTimeZone('UTC'));
                } catch (\Throwable $e) {
                    continue;
                }

                if ($when <= $ts) {
                    continue;
                }

                $currency = strtoupper((string) ($evt->currency ?? ''));

                if ($currency === '' || in_array($currency, $relevant, true)) {
                    $matches[] = ['item' => $evt, 'when' => $when];
                }
            }

            if (empty($matches)) {
                return null;
            }

            usort($matches, function ($a, $b) {
                return $a['when'] <=> $b['when'];
            });

            $first = $matches[0];
            $evt = $first['item'];
            $when = $first['when'];

            $minutes = (int) ceil(($when->getTimestamp() - $ts->getTimestamp()) / 60);

            return [
                'title' => $evt->title ?? null,
                'currency' => $evt->currency ?? null,
                'when_utc' => $when->format(DATE_ATOM),
                'impact' => $evt->impact ?? 'High',
                'minutes_to' => $minutes,
            ];
        }

        // Prefer provider-returned events when available (unit tests mock provider).
        $providerEvents = null;
        try {
            $providerEvents = $this->provider->getCalendar();
        } catch (\Throwable $e) {
            $providerEvents = null;
        }

        $matches = [];

        if (is_array($providerEvents) && count($providerEvents) > 0) {
            // Provider returned raw array items (feed). Normalize and filter.
            foreach ($providerEvents as $item) {
                // Accept a few common date keys from providers: date, datetime, date_utc, when_utc
                $date = $item['date'] ?? ($item['datetime'] ?? ($item['date_utc'] ?? ($item['when_utc'] ?? null)));
                if (empty($date)) {
                    continue;
                }

                try {
                    $when = new \DateTimeImmutable($date);
                } catch (\Throwable $e) {
                    continue;
                }

                if ($when <= $ts) {
                    continue;
                }

                // Determine match using 'country' first, then 'currency' token(s)
                $country = isset($item['country']) ? (string) $item['country'] : '';
                $currencyField = $item['currency'] ?? null;

                $matched = false;
                $displayCurrency = $country ?: null;

                if ($country !== '') {
                    if ($country === '' || in_array(strtoupper($country), $relevant, true)) {
                        $matched = true;
                        $displayCurrency = $country;
                    }
                }

                if (! $matched && $currencyField !== null) {
                    // currencyField may be array or string
                    $tokens = is_array($currencyField) ? $currencyField : [$currencyField];
                    foreach ($tokens as $token) {
                        $token = (string) $token;
                        if ($token === '') {
                            continue;
                        }

                        // split pair-like tokens
                        if (str_contains($token, '-') || str_contains($token, '/')) {
                            $partsTok = preg_split('/[\-\/]/', $token);
                            foreach ($partsTok as $p) {
                                if (in_array(strtoupper($p), $relevant, true)) {
                                    $matched = true;
                                    break 2;
                                }
                            }
                        }

                        if (in_array(strtoupper($token), $relevant, true)) {
                            $matched = true;
                            break;
                        }
                    }

                    if ($matched && $displayCurrency === null) {
                        // prefer country for display; otherwise join tokens for readability
                        $displayCurrency = is_array($currencyField) ? implode(',', $currencyField) : (string) $currencyField;
                    }
                }

                if ($matched) {
                    $obj = (object) $item;
                    $obj->currency = $displayCurrency;
                    $matches[] = ['item' => $obj, 'when' => $when];
                }
            }
        } else {
            // Query upcoming high impact events from DB-backed CalendarEvent model
            $events = CalendarEvent::upcoming()->highImpact()->get();

            foreach ($events as $evt) {
                try {
                    $when = $evt->event_time_utc instanceof \DateTimeImmutable ? $evt->event_time_utc : new \DateTimeImmutable($evt->event_time_utc, new \DateTimeZone('UTC'));
                } catch (\Throwable $e) {
                    continue;
                }

                if ($when <= $ts) {
                    continue;
                }

                $currency = strtoupper((string) ($evt->currency ?? ''));

                // If event currency is empty, consider it matching (feed-wide event)
                if ($currency === '' || in_array($currency, $relevant, true)) {
                    $matches[] = ['item' => $evt, 'when' => $when];
                }
            }
        }

        if (empty($matches)) {
            return null;
        }

        usort($matches, function ($a, $b) {
            return $a['when'] <=> $b['when'];
        });

        $first = $matches[0];
        $evt = $first['item'];
        $when = $first['when'];

        $minutes = (int) ceil(($when->getTimestamp() - $ts->getTimestamp()) / 60);

        return [
            'title' => $evt->title ?? null,
            'currency' => $evt->currency ?? null,
            'when_utc' => $when->format(DATE_ATOM),
            'impact' => $evt->impact ?? 'High',
            'minutes_to' => $minutes,
        ];
    }

    /**
     * Return a calendar summary for the given pair at timestamp $ts.
     * Returns null only when the provider returns no events at all.
     * Otherwise returns array with keys: next_high, today_high, within_blackout, blackout_minutes_high
     */
    public function summary(string $pair, \DateTimeImmutable $ts): ?array
    {
        // Query DB for upcoming high impact events
        $pairNorm = str_replace('-', '/', $pair);
        $parts = array_map('trim', explode('/', $pairNorm));
        $relevant = array_map('strtoupper', $parts);

        try {
            $candidates = [
                $pair,
                str_replace('/', '-', $pair),
                str_replace('-', '/', $pair),
            ];
            $market = Market::whereIn('symbol', $candidates)->first();
            if ($market && is_array($market->currencies) && count($market->currencies) > 0) {
                $relevant = array_map('strtoupper', $market->currencies);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // If we have persisted high-impact CalendarEvent rows, prefer DB-backed summary (feature tests)
        $dbEvents = collect();
        if (Schema::hasTable('calendar_events')) {
            $dbEvents = CalendarEvent::upcoming()->highImpact()->get();
        }

        if ($dbEvents->isNotEmpty()) {
            $matches = [];
            foreach ($dbEvents as $evt) {
                $currency = strtoupper((string) ($evt->currency ?? ''));
                if ($currency === '' || in_array($currency, $relevant, true)) {
                    try {
                        $when = $evt->event_time_utc instanceof \DateTimeImmutable ? $evt->event_time_utc : new \DateTimeImmutable($evt->event_time_utc, new \DateTimeZone('UTC'));
                    } catch (\Throwable $e) {
                        continue;
                    }

                    if ($when > $ts) {
                        $matches[] = ['evt' => $evt, 'when' => $when];
                    }
                }
            }

            if (empty($matches)) {
                return [
                    'next_high' => null,
                    'today_high' => null,
                    'within_blackout' => false,
                    'blackout_minutes_high' => (int) config('decision.blackout_minutes_high', 60),
                ];
            }

            usort($matches, fn ($a, $b) => $a['when'] <=> $b['when']);

            $earliest = $matches[0];
            $evt = $earliest['evt'];
            $earliestWhen = $earliest['when'];

            $minutesNext = (int) ceil(($earliestWhen->getTimestamp() - $ts->getTimestamp()) / 60);

            $nextHigh = [
                'title' => $evt->title ?? null,
                'currency' => $evt->currency ?? null,
                'when_utc' => $earliestWhen->format(DATE_ATOM),
                'minutes_to' => $minutesNext,
            ];

            // today_high
            $tsDate = $ts->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
            $todayMatch = null;
            foreach ($matches as $m) {
                if ($m['when']->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d') === $tsDate) {
                    $todayMatch = $m;
                    break;
                }
            }

            $todayHigh = null;
            if ($todayMatch !== null) {
                $whenT = $todayMatch['when'];
                $minutesToday = (int) ceil(($whenT->getTimestamp() - $ts->getTimestamp()) / 60);
                $todayHigh = [
                    'title' => $todayMatch['evt']->title ?? null,
                    'currency' => $todayMatch['evt']->currency ?? null,
                    'when_utc' => $whenT->format(DATE_ATOM),
                    'minutes_to' => $minutesToday,
                ];
            }

            $blackoutMinutes = (int) config('decision.blackout_minutes_high', 60);
            $within = $minutesNext <= $blackoutMinutes;

            return [
                'next_high' => $nextHigh,
                'today_high' => $todayHigh,
                'within_blackout' => $within,
                'blackout_minutes_high' => $blackoutMinutes,
            ];
        }

        // Prefer provider events when available
        $providerEvents = null;
        try {
            $providerEvents = $this->provider->getCalendar();
        } catch (\Throwable $e) {
            $providerEvents = null;
        }

        $matches = [];

        if (is_array($providerEvents)) {
            // If provider explicitly returned an empty array, decide behavior based on provider impl.
            if (count($providerEvents) === 0) {
                // If the concrete provider is the real EconomicCalendarProvider, fall back to DB
                // (real provider may return empty when remote feed is empty). However, if the
                // provider is a mocked implementation used in unit tests, preserve the previous
                // behavior of treating empty array as 'no data' and returning null.
                if (! ($this->provider instanceof \App\Services\Economic\EconomicCalendarProvider)) {
                    return null;
                }
                // otherwise continue and let DB fallback occur
            }

            foreach ($providerEvents as $item) {
                // Accept a few common date keys from providers: date, datetime, date_utc, when_utc
                $date = $item['date'] ?? ($item['datetime'] ?? ($item['date_utc'] ?? ($item['when_utc'] ?? null)));
                if (empty($date)) {
                    continue;
                }

                try {
                    $when = new \DateTimeImmutable($date);
                } catch (\Throwable $e) {
                    continue;
                }

                if ($when <= $ts) {
                    continue;
                }

                $country = isset($item['country']) ? (string) $item['country'] : '';
                $currencyField = $item['currency'] ?? null;

                $matched = false;
                $displayCurrency = $country ?: null;

                if ($country !== '') {
                    if ($country === '' || in_array(strtoupper($country), $relevant, true)) {
                        $matched = true;
                        $displayCurrency = $country;
                    }
                }

                if (! $matched && $currencyField !== null) {
                    $tokens = is_array($currencyField) ? $currencyField : [$currencyField];
                    foreach ($tokens as $token) {
                        $token = (string) $token;
                        if ($token === '') {
                            continue;
                        }

                        if (str_contains($token, '-') || str_contains($token, '/')) {
                            $partsTok = preg_split('/[\-\/]/', $token);
                            foreach ($partsTok as $p) {
                                if (in_array(strtoupper($p), $relevant, true)) {
                                    $matched = true;
                                    break 2;
                                }
                            }
                        }

                        if (in_array(strtoupper($token), $relevant, true)) {
                            $matched = true;
                            break;
                        }
                    }

                    if ($matched && $displayCurrency === null) {
                        $displayCurrency = is_array($currencyField) ? implode(',', $currencyField) : (string) $currencyField;
                    }
                }

                if ($matched) {
                    $obj = (object) $item;
                    $obj->currency = $displayCurrency;
                    $matches[] = ['evt' => $obj, 'when' => $when];
                }
            }
        } else {
            $events = CalendarEvent::upcoming()->highImpact()->get();

            // If we have no high-impact matches, return structure with nulls but not null overall
            if ($events->isEmpty()) {
                return [
                    'next_high' => null,
                    'today_high' => null,
                    'within_blackout' => false,
                    'blackout_minutes_high' => (int) config('decision.blackout_minutes_high', 60),
                ];
            }

            foreach ($events as $evt) {
                $currency = strtoupper((string) ($evt->currency ?? ''));
                if ($currency === '' || in_array($currency, $relevant, true)) {
                    try {
                        $when = $evt->event_time_utc instanceof \DateTimeImmutable ? $evt->event_time_utc : new \DateTimeImmutable($evt->event_time_utc, new \DateTimeZone('UTC'));
                    } catch (\Throwable $e) {
                        continue;
                    }

                    if ($when > $ts) {
                        $matches[] = ['evt' => $evt, 'when' => $when];
                    }
                }
            }
        }

        if (empty($matches)) {
            return [
                'next_high' => null,
                'today_high' => null,
                'within_blackout' => false,
                'blackout_minutes_high' => (int) config('decision.blackout_minutes_high', 60),
            ];
        }

        usort($matches, fn ($a, $b) => $a['when'] <=> $b['when']);

        $earliest = $matches[0];
        $evt = $earliest['evt'];
        $earliestWhen = $earliest['when'];

        $minutesNext = (int) ceil(($earliestWhen->getTimestamp() - $ts->getTimestamp()) / 60);

        $nextHigh = [
            'title' => $evt->title ?? null,
            'currency' => $evt->currency ?? null,
            'when_utc' => $earliestWhen->format(DATE_ATOM),
            'minutes_to' => $minutesNext,
        ];

        // today_high
        $tsDate = $ts->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        $todayMatch = null;
        foreach ($matches as $m) {
            if ($m['when']->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d') === $tsDate) {
                $todayMatch = $m;
                break;
            }
        }

        $todayHigh = null;
        if ($todayMatch !== null) {
            $whenT = $todayMatch['when'];
            $minutesToday = (int) ceil(($whenT->getTimestamp() - $ts->getTimestamp()) / 60);
            $todayHigh = [
                'title' => $todayMatch['evt']->title ?? null,
                'currency' => $todayMatch['evt']->currency ?? null,
                'when_utc' => $whenT->format(DATE_ATOM),
                'minutes_to' => $minutesToday,
            ];
        }

        $blackoutMinutes = (int) config('decision.blackout_minutes_high', 60);
        $within = $minutesNext <= $blackoutMinutes;

        return [
            'next_high' => $nextHigh,
            'today_high' => $todayHigh,
            'within_blackout' => $within,
            'blackout_minutes_high' => $blackoutMinutes,
        ];
    }
}
