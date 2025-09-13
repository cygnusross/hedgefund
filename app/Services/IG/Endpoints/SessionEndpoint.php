<?php

namespace App\Services\IG\Endpoints;

use App\Services\IG\BaseEndpoint;

class SessionEndpoint extends BaseEndpoint
{
    /**
     * Create a trading session and cache tokens via the Client.
     *
     * @param  array  $credentials  ['identifier' => '', 'password' => '', 'encryptedPassword' => false]
     * @return array The accountInfo returned by the IG API
     */
    public function create(array $credentials): array
    {
        return $this->client->createSession($credentials);
    }
}
