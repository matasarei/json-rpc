<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Server;

use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\MiddlewareHandler;
use JsonRPC\MiddlewareInterface;
use JsonRPC\ProcedureHandler;
use JsonRPC\Server\ErrorResponseFactory;
use JsonRPC\Server\RequestHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RequestHandler::class)]
final class RequestHandlerTest extends TestCase
{
    private ProcedureHandler $procedures;

    private MiddlewareHandler $middleware;

    protected function setUp(): void
    {
        $this->procedures = new ProcedureHandler();
        $this->procedures->withCallback('sum', fn(int $a, int $b): int => $a + $b);
        $this->middleware = new MiddlewareHandler();
    }

    /**
     * @param list<class-string> $localExceptions
     */
    private function handler(bool $mask = true, array $localExceptions = []): RequestHandler
    {
        return new RequestHandler(
            $this->procedures,
            $this->middleware,
            new ErrorResponseFactory($mask),
            $localExceptions,
        );
    }

    public function testAnswersACall(): void
    {
        $response = $this->handler()->handle(
            ['jsonrpc' => '2.0', 'method' => 'sum', 'params' => [3, 4], 'id' => 1],
            null,
            null,
        );

        $this->assertSame(['jsonrpc' => '2.0', 'result' => 7, 'id' => 1], $response);
    }

    public function testAnswersACallWithoutParameters(): void
    {
        $this->procedures->withCallback('ping', fn(): string => 'pong');

        $response = $this->handler()->handle(['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 'abc'], null, null);

        $this->assertSame(['jsonrpc' => '2.0', 'result' => 'pong', 'id' => 'abc'], $response);
    }

    public function testDoesNotAnswerANotification(): void
    {
        $this->assertNull($this->handler()->handle(
            ['jsonrpc' => '2.0', 'method' => 'sum', 'params' => [3, 4]],
            null,
            null,
        ));
    }

    public function testDoesNotAnswerANotificationThatFailed(): void
    {
        $this->assertNull($this->handler()->handle(['jsonrpc' => '2.0', 'method' => 'missing'], null, null));
    }

    public function testAnswersAMalformedRequestEvenWithoutAnId(): void
    {
        $response = $this->handler()->handle(['jsonrpc' => '1.0', 'method' => 'sum'], null, null);

        $this->assertSame(
            ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Invalid Request'], 'id' => null],
            $response,
        );
    }

    public function testRejectsPayloadsThatAreNotRequests(): void
    {
        $invalidRequest = [
            'jsonrpc' => '2.0',
            'error' => ['code' => -32600, 'message' => 'Invalid Request'],
            'id' => null,
        ];

        foreach ([1, 'string', null, ['jsonrpc' => '2.0'], ['method' => 'sum']] as $payload) {
            $this->assertSame($invalidRequest, $this->handler()->handle($payload, null, null));
        }
    }

    public function testRejectsParametersThatAreNotAnArray(): void
    {
        $response = $this->handler()->handle(
            ['jsonrpc' => '2.0', 'method' => 'sum', 'params' => 'nope', 'id' => 1],
            null,
            null,
        );

        $this->assertSame(
            ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Invalid Request'], 'id' => null],
            $response,
        );
    }

    public function testReportsUnknownProcedures(): void
    {
        $response = $this->handler()->handle(['jsonrpc' => '2.0', 'method' => 'missing', 'id' => 1], null, null);

        $this->assertSame(
            ['jsonrpc' => '2.0', 'error' => ['code' => -32601, 'message' => 'Method not found'], 'id' => 1],
            $response,
        );
    }

    public function testMasksTheDetailsOfUnrecognizedExceptions(): void
    {
        $this->procedures->withCallback('boom', function (): never {
            throw new RuntimeException('secret at /var/db/credentials.ini');
        });

        $response = $this->handler()->handle(['jsonrpc' => '2.0', 'method' => 'boom', 'id' => 1], null, null);

        $this->assertSame(
            ['jsonrpc' => '2.0', 'error' => ['code' => -32603, 'message' => 'Internal error'], 'id' => 1],
            $response,
        );
        $this->assertStringNotContainsString('secret', (string) json_encode($response));
    }

    public function testRunsMiddlewareWithTheCredentialsAndProcedureName(): void
    {
        $seen = [];
        $this->middleware->withMiddleware(new class ($seen) implements MiddlewareInterface {
            /**
             * @param array<int, array{string|null, string|null, string}> $seen
             */
            public function __construct(public array &$seen)
            {
            }

            public function execute(?string $username, ?string $password, string $procedureName): void
            {
                $this->seen[] = [$username, $password, $procedureName];
            }
        });

        $this->handler()->handle(['jsonrpc' => '2.0', 'method' => 'sum', 'params' => [1, 2], 'id' => 1], 'me', 'secret');

        $this->assertSame([['me', 'secret', 'sum']], $seen);
    }

    public function testLetsLocalExceptionsBubbleUp(): void
    {
        $this->procedures->withCallback('denied', function (): never {
            throw new AccessDeniedException('Nope');
        });

        $this->expectException(AccessDeniedException::class);

        $this->handler(true, [AccessDeniedException::class])
            ->handle(['jsonrpc' => '2.0', 'method' => 'denied', 'id' => 1], null, null);
    }

    public function testLocalExceptionsBubbleUpFromNotificationsToo(): void
    {
        $this->procedures->withCallback('denied', function (): never {
            throw new AccessDeniedException('Nope');
        });

        $this->expectException(AccessDeniedException::class);

        $this->handler(true, [AccessDeniedException::class])
            ->handle(['jsonrpc' => '2.0', 'method' => 'denied'], null, null);
    }
}
