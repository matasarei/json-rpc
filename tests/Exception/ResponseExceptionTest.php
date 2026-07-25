<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Exception;

use JsonRPC\Exception\ResponseException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ResponseException::class)]
final class ResponseExceptionTest extends TestCase
{
    public function testCarriesMessageCodeAndData(): void
    {
        $exception = new ResponseException('Error', 42, null, ['detail' => 'context']);

        $this->assertSame('Error', $exception->getMessage());
        $this->assertSame(42, $exception->getCode());
        $this->assertSame(['detail' => 'context'], $exception->getData());
        $this->assertSame(['detail' => 'context'], $exception->data);
    }

    public function testDataIsNullByDefault(): void
    {
        $this->assertNull((new ResponseException())->getData());
    }

    public function testKeepsThePreviousException(): void
    {
        $previous = new RuntimeException('root cause');

        $this->assertSame($previous, (new ResponseException('Error', 0, $previous))->getPrevious());
    }
}
