<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Request;

use JsonRPC\Request\RandomIdGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RandomIdGenerator::class)]
final class RandomIdGeneratorTest extends TestCase
{
    public function testGeneratesDistinctPositiveIdentifiers(): void
    {
        $generator = new RandomIdGenerator();
        $ids = [$generator->generate(), $generator->generate(), $generator->generate()];

        $this->assertCount(3, array_unique($ids));
        $this->assertGreaterThan(0, min($ids));
    }
}
