<?php

namespace App\Services\Sentiment;

use App\Domain\Sentiment\Contracts\SentimentProvider;

final class NullSentimentProvider implements SentimentProvider
{
    public function fetch(string $marketId, bool $force = false): ?array
    {
        return null;
    }
}
