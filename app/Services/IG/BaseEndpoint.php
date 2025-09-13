<?php

namespace App\Services\IG;

class BaseEndpoint
{
    protected Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }
}
