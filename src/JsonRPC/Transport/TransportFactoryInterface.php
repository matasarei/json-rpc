<?php

declare(strict_types=1);

namespace JsonRPC\Transport;

/**
 * Builds the transport a client uses when none was injected.
 */
interface TransportFactoryInterface
{
    public function create(TransportOptions $options): TransportInterface;
}
