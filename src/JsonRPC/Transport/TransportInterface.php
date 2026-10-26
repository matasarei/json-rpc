<?php

declare(strict_types=1);

namespace JsonRPC\Transport;

use JsonRPC\Exception\ConnectionFailureException;

/**
 * Sends a payload over the wire and returns whatever came back.
 *
 * Implementations do raw I/O only: authentication, cookies, logging and the
 * interpretation of status codes are the responsibility of the caller.
 */
interface TransportInterface
{
    /**
     * @throws ConnectionFailureException When the request did not complete
     */
    public function send(TransportRequest $request): TransportResponse;
}
