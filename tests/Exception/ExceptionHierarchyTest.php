<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Exception;

use BadFunctionCallException;
use Exception;
use InvalidArgumentException;
use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\Exception\AuthenticationFailureException;
use JsonRPC\Exception\BatchFailedException;
use JsonRPC\Exception\ConnectionFailureException;
use JsonRPC\Exception\InvalidJsonFormatException;
use JsonRPC\Exception\InvalidJsonRpcFormatException;
use JsonRPC\Exception\InvalidParamsException;
use JsonRPC\Exception\JsonRpcException;
use JsonRPC\Exception\MethodNotFoundException;
use JsonRPC\Exception\ResponseEncodingFailureException;
use JsonRPC\Exception\ResponseException;
use JsonRPC\Exception\RpcCallFailedException;
use JsonRPC\Exception\ServerErrorException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AccessDeniedException::class)]
#[CoversClass(AuthenticationFailureException::class)]
#[CoversClass(ConnectionFailureException::class)]
#[CoversClass(InvalidJsonFormatException::class)]
#[CoversClass(InvalidJsonRpcFormatException::class)]
#[CoversClass(InvalidParamsException::class)]
#[CoversClass(MethodNotFoundException::class)]
#[CoversClass(ResponseEncodingFailureException::class)]
#[CoversClass(RpcCallFailedException::class)]
#[CoversClass(ServerErrorException::class)]
final class ExceptionHierarchyTest extends TestCase
{
    /**
     * @return list<array{class-string<JsonRpcException>}>
     */
    public static function exceptionClasses(): array
    {
        return [
            [AccessDeniedException::class],
            [AuthenticationFailureException::class],
            [BatchFailedException::class],
            [ConnectionFailureException::class],
            [InvalidJsonFormatException::class],
            [InvalidJsonRpcFormatException::class],
            [InvalidParamsException::class],
            [MethodNotFoundException::class],
            [ResponseEncodingFailureException::class],
            [ResponseException::class],
            [RpcCallFailedException::class],
            [ServerErrorException::class],
        ];
    }

    /**
     * @param class-string<JsonRpcException> $class
     */
    #[DataProvider('exceptionClasses')]
    public function testEveryExceptionCarriesTheMarkerInterface(string $class): void
    {
        $this->assertInstanceOf(JsonRpcException::class, new $class('failed'));
        $this->assertInstanceOf(Exception::class, new $class('failed'));
    }

    public function testTransportAndProtocolErrorsShareTheSameRoot(): void
    {
        $this->assertInstanceOf(RpcCallFailedException::class, new ConnectionFailureException('down'));
        $this->assertInstanceOf(RpcCallFailedException::class, new ServerErrorException('boom'));
        $this->assertInstanceOf(RpcCallFailedException::class, new AccessDeniedException('nope'));
        $this->assertInstanceOf(RpcCallFailedException::class, new AuthenticationFailureException('nope'));
        $this->assertInstanceOf(RpcCallFailedException::class, new ResponseEncodingFailureException('nope'));
    }

    public function testFormatErrorsAreErrorObjects(): void
    {
        $this->assertInstanceOf(ResponseException::class, new InvalidJsonFormatException('parse'));
        $this->assertInstanceOf(ResponseException::class, new InvalidJsonRpcFormatException('shape'));
    }

    /**
     * The two bridges extend the SPL exceptions the server maps to -32601/-32602,
     * so procedures throwing the SPL types and consumers catching them keep working.
     */
    public function testSplBridgesKeepTheirNativeParents(): void
    {
        $this->assertInstanceOf(BadFunctionCallException::class, new MethodNotFoundException('missing'));
        $this->assertInstanceOf(InvalidArgumentException::class, new InvalidParamsException('bad'));
    }
}
