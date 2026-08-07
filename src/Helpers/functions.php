<?php

declare(strict_types=1);

use Observer\Contracts\ClientInterface;

if (! function_exists('observer')) {
    /**
     * Resolve o cliente do Observer a partir do container.
     *
     * observer()->capture($e);
     * observer()->event('checkout.completed', ['total' => 199.90]);
     */
    function observer(): ClientInterface
    {
        /** @var ClientInterface $client */
        $client = app(ClientInterface::class);

        return $client;
    }
}
