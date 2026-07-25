<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Server;

use BadFunctionCallException;
use InvalidArgumentException;
use JsonRPC\Exception\InvalidJsonFormatException;
use JsonRPC\Exception\InvalidJsonRpcFormatException;
use JsonRPC\Exception\InvalidParamsException;
use JsonRPC\Exception\MethodNotFoundException;
use JsonRPC\Exception\ResponseEncodingFailureException;
use JsonRPC\Exception\ResponseException;
use JsonRPC\Server\ErrorResponseFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ErrorResponseFactory::class)]
final class ErrorResponseFactoryTest extends TestCase
{
    private ErrorResponseFactory $masking;

    private ErrorResponseFactory $relaying;

    protected function setUp(): void
    {
        $this->masking = new ErrorResponseFactory();
        $this->relaying = new ErrorResponseFactory(false);
    }

    public function testMapsTheProtocolErrors(): void
    {
        $this->assertSame(
            ['code' => -32700, 'message' => 'Parse error'],
            $this->masking->create(new InvalidJsonFormatException('Malformed payload')),
        );
        $this->assertSame(
            ['code' => -32600, 'message' => 'Invalid Request'],
            $this->masking->create(new InvalidJsonRpcFormatException('Invalid JSON RPC payload')),
        );
    }

    public function testMapsUnknownProceduresIncludingTheSplException(): void
    {
        $expected = ['code' => -32601, 'message' => 'Method not found'];

        $this->assertSame($expected, $this->masking->create(new MethodNotFoundException('Unable to find')));
        $this->assertSame($expected, $this->masking->create(new BadFunctionCallException('Unable to find')));
    }

    public function testMapsParameterErrorsIncludingTheSplException(): void
    {
        $this->assertSame(
            ['code' => -32602, 'message' => 'Invalid params'],
            $this->masking->create(new InvalidParamsException('Missing argument: a')),
        );
        $this->assertSame(
            ['code' => -32602, 'message' => 'Invalid params', 'data' => 'Missing argument: a'],
            $this->relaying->create(new InvalidArgumentException('Missing argument: a')),
        );
    }

    public function testKeepsErrorObjectsAsTheyAre(): void
    {
        $this->assertSame(
            ['code' => 42, 'message' => 'Custom failure', 'data' => ['field' => 'name']],
            $this->masking->create(new ResponseException('Custom failure', 42, null, ['field' => 'name'])),
        );
        $this->assertSame(
            ['code' => 42, 'message' => 'Custom failure'],
            $this->masking->create(new ResponseException('Custom failure', 42)),
        );
    }

    public function testMasksUnrecognizedExceptionsByDefault(): void
    {
        $this->assertSame(
            ['code' => -32603, 'message' => 'Internal error'],
            $this->masking->create(new RuntimeException('SQLSTATE[42000] at /var/db/credentials.ini', 1234)),
        );
    }

    public function testRelaysUnrecognizedExceptionsWhenMaskingIsOff(): void
    {
        $this->assertSame(
            ['code' => 1234, 'message' => 'Database is down'],
            $this->relaying->create(new RuntimeException('Database is down', 1234)),
        );
    }

    public function testMasksEncodingFailures(): void
    {
        $this->assertSame(
            ['code' => -32603, 'message' => 'Internal error'],
            $this->masking->create(new ResponseEncodingFailureException('Malformed UTF-8')),
        );
        $this->assertSame(
            ['code' => -32603, 'message' => 'Internal error', 'data' => 'Malformed UTF-8'],
            $this->relaying->create(new ResponseEncodingFailureException('Malformed UTF-8')),
        );
    }

    public function testDropsEmptyData(): void
    {
        $this->assertSame(
            ['code' => -32602, 'message' => 'Invalid params'],
            $this->relaying->create(new InvalidArgumentException('')),
        );
    }
}
