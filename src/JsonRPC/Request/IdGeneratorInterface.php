<?php

declare(strict_types=1);

namespace JsonRPC\Request;

/**
 * Produces the identifier of a request when the caller did not choose one.
 */
interface IdGeneratorInterface
{
    public function generate(): int|string;
}
