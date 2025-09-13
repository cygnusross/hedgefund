<?php

namespace App\Services\IG\Endpoints;

use App\Services\IG\BaseEndpoint;

class ClientSentimentEndpoint extends BaseEndpoint
{
    /**
     * Get client sentiment for a market by marketId.
     */
    public function get(string $marketId): array
    {
        $resp = $this->client->get('/clientsentiment/'.urlencode($marketId));

        return $resp['body'] ?? [];
    }
}
