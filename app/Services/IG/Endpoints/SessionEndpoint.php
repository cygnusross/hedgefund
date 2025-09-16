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

    /**
     * Switch to a different account.
     *
     * @param  string  $accountId  The account ID to switch to
     * @param  bool  $setAsDefault  Whether to set this as the default account
     * @return array The response from IG API
     */
    public function switchAccount(string $accountId, bool $setAsDefault = false): array
    {
        $payload = [
            'accountId' => $accountId,
            'defaultAccount' => $setAsDefault,
        ];

        $resp = $this->client->put('/session', $payload);

        return $resp['body'] ?? [];
    }
}
