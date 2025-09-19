<?php

namespace App\Domain\Sentiment\Contracts;

interface SentimentProvider
{
    /**
     * @return array{long_pct: float, short_pct: float, as_of: string}|null
     */
    public function fetch(string $marketId, bool $force = false): ?array;
}
