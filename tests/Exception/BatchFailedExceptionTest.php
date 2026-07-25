<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Exception;

use JsonRPC\Exception\BatchFailedException;
use JsonRPC\Exception\MethodNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BatchFailedException::class)]
final class BatchFailedExceptionTest extends TestCase
{
    public function testKeepsSuccessfulResultsAndErrorsKeyedByCallPosition(): void
    {
        $error = new MethodNotFoundException('Procedure not found: missing');
        $exception = new BatchFailedException('1 of 2 calls failed', [0 => 'ok'], [1 => $error]);

        $this->assertSame('1 of 2 calls failed', $exception->getMessage());
        $this->assertSame([0 => 'ok'], $exception->getResults());
        $this->assertSame([1 => $error], $exception->getErrors());
    }

    public function testResultsAndErrorsDefaultToEmptyArrays(): void
    {
        $exception = new BatchFailedException('failed');

        $this->assertSame([], $exception->getResults());
        $this->assertSame([], $exception->getErrors());
    }
}
