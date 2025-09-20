<?php

namespace App\Services\Economic;

use App\Models\CalendarEvent;
use App\Models\Market;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class EconomicCalendarProvider implements EconomicCalendarProviderContract
{
    protected string $url;

    protected int $ttl;

    public function __construct()
    {
        // Do not access config in constructor; load lazily in getCalendar() to avoid test bootstrap issues.
        $this->url = '';
        $this->ttl = 300;
    }

    /**
     * Fetch and return normalized economic calendar data.
     * Normalized fields per item: title, country, date_utc (ISO8601), impact
     * Results are cached for configured TTL seconds.
     */
    public function getCalendar(bool $force = false): array
    {
        // Always fetch live data (no cache). The refresh command is responsible for persisting.
        // Lazily resolve configured URL so tests that instantiate this class without app bindings don't fail.
        $url = $this->url;
        try {
            if (empty($url)) {
                $url = config('economic.calendar_url') ?: $this->url;
            }
        } catch (\Throwable $e) {
            // keep $url as-is
        }

        $resp = Http::get($url ?: '');
        $data = $resp->json() ?: [];
        $normalized = [];
        foreach ($data as $item) {
            $title = isset($item['title']) ? (string) $item['title'] : '';
            $country = strtoupper((string) ($item['country'] ?? ''));
            $currency = strtoupper((string) ($item['currency'] ?? ''));

            $dateUtc = '1970-01-01T00:00:00Z'; // Default value
            try {
                if (! empty($item['date'])) {
                    $dateUtc = Carbon::parse($item['date'])->utc()->toIso8601String();
                }
            } catch (\Throwable $e) {
                // Log the error and use the default value
                logger()->error('Failed to parse date', ['date' => $item['date'], 'error' => $e->getMessage()]);
            }

            $impact = ucfirst(strtolower((string) ($item['impact'] ?? '')));

            $normalized[] = [
                'title' => $title,
                'country' => $country,
                'currency' => $currency,
                'date_utc' => $dateUtc,
                'impact' => $impact,
                'source' => $url ?: 'ff_json',
            ];
        }

        return $normalized;
    }

    /**
     * Ingest normalized calendar items into the `calendar_events` table.
     * Each item must include: title, currency, date_utc, impact, source
     */
    public function ingest(array $items): void
    {
        foreach ($items as $it) {
            if (empty($it['title']) || empty($it['date_utc']) || empty($it['impact'])) {
                continue;
            }

            $impact = ucfirst(strtolower(trim((string) ($it['impact'] ?? ''))));
            if (! in_array($impact, ['Medium', 'High'], true)) {
                continue;
            }

            $country = strtoupper(trim((string) ($it['country'] ?? '')));

            if ($country === 'ALL') {
                $currencies = $this->uniqueCurrenciesFromMarkets();
                foreach ($currencies as $cc) {
                    $payload = [
                        'title' => $it['title'],
                        'currency' => $cc,
                        'impact' => $impact,
                        'event_time' => $it['date_utc'],
                        'source' => $it['source'] ?? 'ff_json',
                    ];

                    try {
                        CalendarEvent::upsertFromFeed($payload);
                    } catch (\Throwable $e) {
                        // ignore and continue
                    }
                }

                continue;
            }

            // Treat country as currency code directly
            $cc = $country;
            $payload = [
                'title' => $it['title'],
                'currency' => $cc,
                'impact' => $impact,
                'event_time' => $it['date_utc'],
                'source' => $it['source'] ?? 'ff_json',
            ];

            try {
                CalendarEvent::upsertFromFeed($payload);
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    /**
     * Collect unique ISO-3 currencies from active markets.
     * Supports values stored as arrays, JSON strings, or bracketed strings.
     *
     * @return array<string>
     */
    private function uniqueCurrenciesFromMarkets(): array
    {
        try {
            $raw = Market::query()->where('is_active', true)->pluck('currencies')->all();
        } catch (\Throwable $e) {
            return [];
        }

        $tokens = [];
        foreach ($raw as $arr) {
            $values = [];

            if (is_array($arr)) {
                $values = $arr;
            } elseif (is_string($arr) && $arr !== '') {
                $decoded = json_decode($arr, true);
                if (is_array($decoded)) {
                    $values = $decoded;
                } else {
                    $str = trim($arr);
                    $str = trim($str, "[]\"' ");
                    if ($str !== '') {
                        $parts = preg_split('/\s*,\s*/', $str);
                        foreach ($parts as $p) {
                            if ($p !== '') {
                                $values[] = $p;
                            }
                        }
                    }
                }
            }

            foreach ($values as $c) {
                $c = trim((string) $c);
                if ($c !== '') {
                    $tokens[] = strtoupper($c);
                }
            }
        }

        $unique = array_values(array_unique($tokens));
        sort($unique);

        return $unique;
    }
}
