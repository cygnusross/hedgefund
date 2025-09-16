<?php

namespace App\Services\IG\Endpoints;

use App\Services\IG\BaseEndpoint;

class WorkingOrdersOtcEndpoint extends BaseEndpoint
{
    /**
     * Create an OTC working order (version 2 endpoint).
     *
     * @param  array  $payload  Flat payload for IG API (no 'request' wrapper needed)
     * @return array ['dealReference' => string]
     */
    public function create(array $payload): array
    {
        // Debug log the actual payload being sent
        \Illuminate\Support\Facades\Log::info('IG Working Order Payload', [
            'raw_payload' => $payload,
        ]);

        // Send the flat payload directly (VERSION 2 expects fields at top level)
        $resp = $this->client->post('/workingorders/otc', $payload);

        $body = $resp['body'] ?? [];

        return ['dealReference' => $body['dealReference'] ?? null];
    }
}
