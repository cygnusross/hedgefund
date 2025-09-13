<?php

namespace App\Services\Economic;

interface EconomicCalendarProviderContract
{
    /**
     * Return normalized calendar items array
     */
    public function getCalendar(bool $force = false): array;

    /**
     * Ingest normalized calendar items into persistence (optional implementation).
     */
    public function ingest(array $items): void;
}
