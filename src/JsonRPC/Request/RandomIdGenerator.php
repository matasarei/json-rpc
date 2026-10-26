<?php

declare(strict_types=1);

namespace JsonRPC\Request;

/**
 * Random identifiers, unique enough to correlate the answers of a batch.
 */
final readonly class RandomIdGenerator implements IdGeneratorInterface
{
    public function generate(): int
    {
        return random_int(1, PHP_INT_MAX);
    }
}
