<?php

namespace App\Services\IG\Endpoints;

use App\Services\IG\BaseEndpoint;
use App\Services\IG\DTO\WorkingOrderRequest;

class WorkingOrdersOtcEndpoint extends BaseEndpoint
{
    /**
     * Create an OTC working order (version 2 endpoint).
     *
     * @param  array  $payload  Expecting ['request' => [...]] where request follows IG spec
     * @return array ['dealReference' => string]
     */
    public function create(array $payload): array
    {
        // Validate and normalize payload
        $normalized = WorkingOrderRequest::validate($payload);

        $resp = $this->client->post('/working-orders/otc', $normalized);

        $body = $resp['body'] ?? [];

        return ['dealReference' => $body['dealReference'] ?? null];
    }
}
