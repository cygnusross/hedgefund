<?php

namespace App\Services\IG\Endpoints;

use App\Services\IG\BaseEndpoint;

class MarketsEndpoint extends BaseEndpoint
{
    /**
     * Get market details for the given epic.
     */
    public function get(string $epic): array
    {
        $resp = $this->client->get('/markets/'.urlencode($epic));

        return $resp['body'] ?? [];
    }
}
