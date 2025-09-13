<?php

namespace App\Services\IG\Endpoints;

use App\Services\IG\BaseEndpoint;

class AccountsEndpoint extends BaseEndpoint
{
    /**
     * Return a list of the logged-in client's accounts.
     */
    public function list(): array
    {
        $resp = $this->client->get('/accounts');

        // The IG API returns an object with 'accounts' key per the spec
        $body = $resp['body'] ?? [];

        return $body['accounts'] ?? $body;
    }
}
