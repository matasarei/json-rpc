<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Doubles;

use JsonRPC\Request\IdGeneratorInterface;

/**
 * Predictable request ids, so payloads can be asserted verbatim.
 */
final class SequentialIdGenerator implements IdGeneratorInterface
{
    public function __construct(private int $next = 1)
    {
    }

    public function generate(): int
    {
        return $this->next++;
    }
}
