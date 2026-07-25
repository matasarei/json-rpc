<?php

declare(strict_types=1);

namespace JsonRPC\Tests;

use JsonRPC\ErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ErrorCode::class)]
final class ErrorCodeTest extends TestCase
{
    public function testCodesMatchTheSpecification(): void
    {
        $this->assertSame(-32700, ErrorCode::ParseError->value);
        $this->assertSame(-32600, ErrorCode::InvalidRequest->value);
        $this->assertSame(-32601, ErrorCode::MethodNotFound->value);
        $this->assertSame(-32602, ErrorCode::InvalidParams->value);
        $this->assertSame(-32603, ErrorCode::InternalError->value);
    }

    public function testEveryCaseHasTheSpecificationMessage(): void
    {
        $this->assertSame('Parse error', ErrorCode::ParseError->message());
        $this->assertSame('Invalid Request', ErrorCode::InvalidRequest->message());
        $this->assertSame('Method not found', ErrorCode::MethodNotFound->message());
        $this->assertSame('Invalid params', ErrorCode::InvalidParams->message());
        $this->assertSame('Internal error', ErrorCode::InternalError->message());
    }

    public function testCodesCanBeResolvedBackToACase(): void
    {
        $this->assertSame(ErrorCode::MethodNotFound, ErrorCode::from(-32601));
    }
}
