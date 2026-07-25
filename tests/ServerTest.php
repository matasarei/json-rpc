<?php

declare(strict_types=1);

namespace JsonRPC\Tests;

use DomainException;
use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\MiddlewareHandler;
use JsonRPC\MiddlewareInterface;
use JsonRPC\ProcedureHandler;
use JsonRPC\Server;
use JsonRPC\Server\ServerRequest;
use JsonRPC\Tests\Doubles\Procedures;
use JsonSerializable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Server::class)]
final class ServerTest extends TestCase
{
    private Server $server;

    protected function setUp(): void
    {
        $this->server = new Server();
        $this->server->getProcedureHandler()->withCallback('sum', fn(int $a, int $b): int => $a + $b);
    }

    /**
     * @param array<string, mixed> $serverVariables
     */
    private function call(string $body, array $serverVariables = []): string
    {
        return $this->server->execute(ServerRequest::fromString($body, $serverVariables))->body;
    }

    public function testAnswersACall(): void
    {
        $response = $this->server->execute(
            ServerRequest::fromString('{"jsonrpc":"2.0","method":"sum","params":[3,4],"id":1}'),
        );

        $this->assertSame('{"jsonrpc":"2.0","result":7,"id":1}', $response->body);
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['Content-Type' => 'application/json'], $response->headers);
    }

    public function testAnswersNothingToANotification(): void
    {
        $response = $this->server->execute(ServerRequest::fromString('{"jsonrpc":"2.0","method":"sum","params":[3,4]}'));

        $this->assertSame('', $response->body);
        $this->assertSame(204, $response->statusCode);
        $this->assertSame([], $response->headers);
    }

    public function testReportsAMalformedPayload(): void
    {
        $this->assertSame(
            '{"jsonrpc":"2.0","error":{"code":-32700,"message":"Parse error"},"id":null}',
            $this->call('{"jsonrpc": "2.0", "method": '),
        );
    }

    public function testAnswersABatch(): void
    {
        $body = $this->call('[
            {"jsonrpc":"2.0","method":"sum","params":[3,4],"id":1},
            {"jsonrpc":"2.0","method":"sum","params":[1,1],"id":2}
        ]');

        $this->assertSame('[{"jsonrpc":"2.0","result":7,"id":1},{"jsonrpc":"2.0","result":2,"id":2}]', $body);
    }

    public function testAnswersNothingToABatchOfNotifications(): void
    {
        $response = $this->server->execute(ServerRequest::fromString(
            '[{"jsonrpc":"2.0","method":"sum","params":[3,4]},{"jsonrpc":"2.0","method":"sum","params":[1,1]}]',
        ));

        $this->assertSame('', $response->body);
        $this->assertSame(204, $response->statusCode);
    }

    public function testRejectsABatchOverTheLimit(): void
    {
        $this->server->withBatchLimit(2);

        $body = $this->call('[
            {"jsonrpc":"2.0","method":"sum","params":[1,1],"id":1},
            {"jsonrpc":"2.0","method":"sum","params":[1,1],"id":2},
            {"jsonrpc":"2.0","method":"sum","params":[1,1],"id":3}
        ]');

        $this->assertSame(
            '{"jsonrpc":"2.0","error":{"code":-32600,"message":"Invalid Request"},"id":null}',
            $body,
        );
    }

    public function testAcceptsABatchOfExactlyTheLimit(): void
    {
        $this->server->withBatchLimit(2);

        $body = $this->call('[
            {"jsonrpc":"2.0","method":"sum","params":[1,1],"id":1},
            {"jsonrpc":"2.0","method":"sum","params":[2,2],"id":2}
        ]');

        $this->assertSame('[{"jsonrpc":"2.0","result":2,"id":1},{"jsonrpc":"2.0","result":4,"id":2}]', $body);
    }

    public function testABatchLimitOfZeroMeansNoLimit(): void
    {
        $this->server->withBatchLimit(0);

        $requests = array_fill(0, 200, '{"jsonrpc":"2.0","method":"sum","params":[1,1],"id":1}');

        $this->assertStringStartsWith('[{"jsonrpc":"2.0","result":2', $this->call('[' . implode(',', $requests) . ']'));
    }

    public function testTheDefaultBatchLimitIsOneHundred(): void
    {
        $requests = array_fill(0, 101, '{"jsonrpc":"2.0","method":"sum","params":[1,1],"id":1}');

        $this->assertSame(
            '{"jsonrpc":"2.0","error":{"code":-32600,"message":"Invalid Request"},"id":null}',
            $this->call('[' . implode(',', $requests) . ']'),
        );
    }

    public function testMasksInternalErrorsByDefault(): void
    {
        $this->server->getProcedureHandler()->withCallback('boom', function (): never {
            throw new RuntimeException('secret at /var/db/credentials.ini', 1234);
        });

        $body = $this->call('{"jsonrpc":"2.0","method":"boom","id":1}');

        $this->assertSame('{"jsonrpc":"2.0","error":{"code":-32603,"message":"Internal error"},"id":1}', $body);
    }

    public function testRelaysInternalErrorsWhenMaskingIsTurnedOff(): void
    {
        $this->server->withInternalErrorMasking(false);
        $this->server->getProcedureHandler()->withCallback('boom', function (): never {
            throw new RuntimeException('Database is down', 1234);
        });

        $body = $this->call('{"jsonrpc":"2.0","method":"boom","id":1}');

        $this->assertSame('{"jsonrpc":"2.0","error":{"code":1234,"message":"Database is down"},"id":1}', $body);
    }

    public function testTurnsErrorsThrownByPhpIntoInternalErrors(): void
    {
        $this->server->getProcedureHandler()->withCallback('broken', fn(): int => intdiv(1, 0));

        $body = $this->call('{"jsonrpc":"2.0","method":"broken","id":1}');

        $this->assertSame('{"jsonrpc":"2.0","error":{"code":-32603,"message":"Internal error"},"id":1}', $body);
    }

    public function testReportsAResponseThatCannotBeEncodedKeepingItsId(): void
    {
        $this->server->getProcedureHandler()->withCallback('binary', fn(): string => "\xB1\x31");

        $body = $this->call('{"jsonrpc":"2.0","method":"binary","id":1}');

        $this->assertSame('{"jsonrpc":"2.0","error":{"code":-32603,"message":"Internal error"},"id":1}', $body);
    }

    public function testOneUnencodableResultDoesNotTakeTheWholeBatchDown(): void
    {
        $this->server->getProcedureHandler()->withCallback('binary', fn(): string => "\xB1\x31");

        $body = $this->call('[
            {"jsonrpc":"2.0","method":"sum","params":[3,4],"id":1},
            {"jsonrpc":"2.0","method":"binary","id":2}
        ]');

        $this->assertSame(
            '[{"jsonrpc":"2.0","result":7,"id":1},'
            . '{"jsonrpc":"2.0","error":{"code":-32603,"message":"Internal error"},"id":2}]',
            $body,
        );
    }

    public function testKeepsTheBatchReadableWhenAnErrorMessageIsNotValidUtf8(): void
    {
        $this->server->withInternalErrorMasking(false);
        $this->server->getProcedureHandler()
            ->withCallback('ping', fn(): string => 'pong')
            ->withCallback('entity', fn(): object => new class implements JsonSerializable {
                public function jsonSerialize(): mixed
                {
                    throw new RuntimeException("row \xE9\xE8 is broken");
                }
            });

        $body = $this->call('[
            {"jsonrpc":"2.0","method":"ping","id":"a"},
            {"jsonrpc":"2.0","method":"entity","id":"b"}
        ]');

        /** @var list<array{result?: string, error?: array{code: int}}> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(2, $decoded);
        $this->assertSame('pong', $decoded[0]['result'] ?? null);
        $this->assertSame(-32603, $decoded[1]['error']['code'] ?? null);
    }

    public function testAnswersAStatusCodeForAnAccessFailureRaisedWhileEncoding(): void
    {
        $this->server->getProcedureHandler()->withCallback('entity', fn(): object => new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new AccessDeniedException('Not for you');
            }
        });

        $response = $this->server->execute(ServerRequest::fromString('{"jsonrpc":"2.0","method":"entity","id":1}'));

        $this->assertSame(403, $response->statusCode);
    }

    public function testReportsAnExceptionRaisedWhileEncodingTheResponse(): void
    {
        $this->server->getProcedureHandler()->withCallback('entity', fn(): object => new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new RuntimeException('secret at /var/db/credentials.ini');
            }
        });

        $body = $this->call('{"jsonrpc":"2.0","method":"entity","id":1}');

        $this->assertSame('{"jsonrpc":"2.0","error":{"code":-32603,"message":"Internal error"},"id":1}', $body);
        $this->assertStringNotContainsString('secret', $body);
    }

    public function testAnswersARequestCarryingANullId(): void
    {
        $this->assertSame(
            '{"jsonrpc":"2.0","result":7,"id":null}',
            $this->call('{"jsonrpc":"2.0","method":"sum","params":[3,4],"id":null}'),
        );
    }

    public function testARefusedElementDoesNotTakeDownTheRestOfTheBatch(): void
    {
        $body = $this->call('[
            {"jsonrpc":"2.0","method":"sum","params":[3,4],"id":1},
            {"jsonrpc":"2.0","method":"sum","params":[1,1],"id":1e400}
        ]');

        $this->assertSame(
            '[{"jsonrpc":"2.0","result":7,"id":1},'
            . '{"jsonrpc":"2.0","error":{"code":-32600,"message":"Invalid Request"},"id":null}]',
            $body,
        );
    }

    public function testRejectsAnIdThatIsNotAStringANumberOrNull(): void
    {
        foreach (['{"a":1}', '[1,2]', 'true', '1e400'] as $id) {
            $this->assertSame(
                '{"jsonrpc":"2.0","error":{"code":-32600,"message":"Invalid Request"},"id":null}',
                $this->call(sprintf('{"jsonrpc":"2.0","method":"sum","params":[3,4],"id":%s}', $id)),
            );
        }
    }

    public function testCallsVariadicProcedures(): void
    {
        $this->server->getProcedureHandler()->withCallback('collect', fn(int ...$numbers): array => $numbers);

        $this->assertSame(
            '{"jsonrpc":"2.0","result":[1,2,3],"id":1}',
            $this->call('{"jsonrpc":"2.0","method":"collect","params":[1,2,3],"id":1}'),
        );
    }

    public function testCallsAVariadicProcedureWithNamedParameters(): void
    {
        $this->server->getProcedureHandler()->withCallback(
            'tag',
            fn(string $name, string ...$rest): array => [$name, $rest],
        );

        $this->assertSame(
            '{"jsonrpc":"2.0","result":["x",[]],"id":1}',
            $this->call('{"jsonrpc":"2.0","method":"tag","params":{"name":"x"},"id":1}'),
        );
    }

    public function testCallsProceduresWhoseNameLooksLikeANumber(): void
    {
        $this->server->getProcedureHandler()->withCallbackArray(['123' => fn(): string => 'numeric']);

        $this->assertSame(
            '{"jsonrpc":"2.0","result":"numeric","id":1}',
            $this->call('{"jsonrpc":"2.0","method":"123","id":1}'),
        );
    }

    public function testReportsAParameterOfTheWrongTypeAsInvalidParams(): void
    {
        $this->assertSame(
            '{"jsonrpc":"2.0","error":{"code":-32602,"message":"Invalid params"},"id":1}',
            $this->call('{"jsonrpc":"2.0","method":"sum","params":["a","b"],"id":1}'),
        );
        $this->assertSame(
            '{"jsonrpc":"2.0","error":{"code":-32602,"message":"Invalid params"},"id":1}',
            $this->call('{"jsonrpc":"2.0","method":"sum","params":[null,23],"id":1}'),
        );
    }

    public function testABroadLocalExceptionDoesNotTakeAwayThe401Answer(): void
    {
        $this->server->authentication(['user' => 'pass'])->withLocalException(RuntimeException::class);

        $response = $this->server->execute(ServerRequest::fromString('{"jsonrpc":"2.0","method":"sum","id":1}'));

        $this->assertSame(401, $response->statusCode);
        $this->assertSame('Basic realm="JsonRPC"', $response->headers['WWW-Authenticate']);
    }

    public function testABroadLocalExceptionDoesNotTakeAwayThe403Answer(): void
    {
        $this->server->allowHosts(['192.168.0.1'])->withLocalException(RuntimeException::class);
        $this->server->getMiddlewareHandler()->withMiddleware(new class implements MiddlewareInterface {
            public function execute(?string $username, ?string $password, string $procedureName): void
            {
                throw new AccessDeniedException('Not for you');
            }
        });

        $hostDenied = $this->server->execute(ServerRequest::fromString(
            '{"jsonrpc":"2.0","method":"sum","id":1}',
            ['REMOTE_ADDR' => '10.0.0.1'],
        ));
        $this->assertSame(403, $hostDenied->statusCode);

        $this->server->allowHosts([]);
        $middlewareDenied = $this->server->execute(
            ServerRequest::fromString('{"jsonrpc":"2.0","method":"sum","id":1}'),
        );
        $this->assertSame(403, $middlewareDenied->statusCode);
    }

    public function testAnExceptionThatIsNotRegisteredIsStillAnsweredToTheClient(): void
    {
        // A parse error is raised outside the request handler, so it reaches
        // the same place a local exception would.
        $this->server->withLocalException(DomainException::class);

        $this->assertSame(
            '{"jsonrpc":"2.0","error":{"code":-32700,"message":"Parse error"},"id":null}',
            $this->call('{"jsonrpc": "2.0", "method": '),
        );
    }

    public function testALocalExceptionRaisedWhileEncodingBubblesOut(): void
    {
        $this->server->withLocalException(RuntimeException::class);
        $this->server->getProcedureHandler()->withCallback('entity', fn(): object => new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new RuntimeException('handled by the application');
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('handled by the application');

        $this->server->execute(ServerRequest::fromString('{"jsonrpc":"2.0","method":"entity","id":1}'));
    }

    public function testLetsALocalExceptionExtendingAccessDeniedBubbleOut(): void
    {
        $exception = new class ('tenant mismatch') extends AccessDeniedException {
        };

        $this->server->withLocalException($exception::class);
        $this->server->getProcedureHandler()->withCallback('boom', function () use ($exception): never {
            throw $exception;
        });

        $this->expectExceptionObject($exception);

        $this->server->execute(ServerRequest::fromString('{"jsonrpc":"2.0","method":"boom","id":1}'));
    }

    public function testAnswers401WithoutTheExpectedCredentials(): void
    {
        $this->server->authentication(['user' => 'pass']);

        $response = $this->server->execute(ServerRequest::fromString('{"jsonrpc":"2.0","method":"sum","id":1}'));

        $this->assertSame(401, $response->statusCode);
        $this->assertSame('Basic realm="JsonRPC"', $response->headers['WWW-Authenticate']);
        $this->assertSame('{"jsonrpc":"2.0","error":{"code":401,"message":"Unauthorized"},"id":null}', $response->body);
    }

    public function testAnswersACallCarryingTheExpectedCredentials(): void
    {
        $this->server->authentication(['user' => 'pass']);

        $body = $this->call(
            '{"jsonrpc":"2.0","method":"sum","params":[3,4],"id":1}',
            ['PHP_AUTH_USER' => 'user', 'PHP_AUTH_PW' => 'pass'],
        );

        $this->assertSame('{"jsonrpc":"2.0","result":7,"id":1}', $body);
    }

    public function testReadsCredentialsFromTheConfiguredHeader(): void
    {
        $this->server->authentication(['user' => 'pass'])->withAuthenticationHeader('X-Auth');

        $body = $this->call(
            '{"jsonrpc":"2.0","method":"sum","params":[3,4],"id":1}',
            ['HTTP_X_AUTH' => base64_encode('user:pass')],
        );

        $this->assertSame('{"jsonrpc":"2.0","result":7,"id":1}', $body);
    }

    public function testAnEmptyAuthenticationHeaderNameKeepsTheStandardCredentials(): void
    {
        $this->server->authentication(['user' => 'pass'])->withAuthenticationHeader('');

        $body = $this->call(
            '{"jsonrpc":"2.0","method":"sum","params":[3,4],"id":1}',
            ['PHP_AUTH_USER' => 'user', 'PHP_AUTH_PW' => 'pass'],
        );

        $this->assertSame('{"jsonrpc":"2.0","result":7,"id":1}', $body);
    }

    public function testAnswers403ToAClientThatIsNotAllowed(): void
    {
        $this->server->allowHosts(['192.168.0.1']);

        $response = $this->server->execute(ServerRequest::fromString(
            '{"jsonrpc":"2.0","method":"sum","id":1}',
            ['REMOTE_ADDR' => '10.0.0.1'],
        ));

        $this->assertSame(403, $response->statusCode);
        $this->assertSame('{"jsonrpc":"2.0","error":{"code":403,"message":"Forbidden"},"id":null}', $response->body);
    }

    public function testAnswersAClientThatIsAllowed(): void
    {
        $this->server->allowHosts(['192.168.0.0/24']);

        $body = $this->call(
            '{"jsonrpc":"2.0","method":"sum","params":[3,4],"id":1}',
            ['REMOTE_ADDR' => '192.168.0.42'],
        );

        $this->assertSame('{"jsonrpc":"2.0","result":7,"id":1}', $body);
    }

    public function testAnswers401WhenAMiddlewareRejectsTheCredentials(): void
    {
        $this->server->getMiddlewareHandler()->withMiddleware(new class implements MiddlewareInterface {
            public function execute(?string $username, ?string $password, string $procedureName): void
            {
                throw new AccessDeniedException('Not for you');
            }
        });

        $response = $this->server->execute(ServerRequest::fromString('{"jsonrpc":"2.0","method":"sum","id":1}'));

        $this->assertSame(403, $response->statusCode);
    }

    public function testLetsRegisteredLocalExceptionsBubbleOutOfExecute(): void
    {
        $this->server->withLocalException(RuntimeException::class);
        $this->server->getProcedureHandler()->withCallback('boom', function (): never {
            throw new RuntimeException('handled by the application');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('handled by the application');

        $this->server->execute(ServerRequest::fromString('{"jsonrpc":"2.0","method":"boom","id":1}'));
    }

    public function testExposesProceduresBoundToAnObject(): void
    {
        $this->server->getProcedureHandler()->withObject(new Procedures(), ['greet']);

        $body = $this->call('{"jsonrpc":"2.0","method":"greet","params":{"name":"Bob"},"id":1}');

        $this->assertSame('{"jsonrpc":"2.0","result":"Hello Bob","id":1}', $body);
    }

    public function testAcceptsPreBuiltCollaborators(): void
    {
        $server = new Server(new ProcedureHandler(), new MiddlewareHandler());
        $server->getProcedureHandler()->withCallback('ping', fn(): string => 'pong');

        $this->assertSame(
            '{"jsonrpc":"2.0","result":"pong","id":1}',
            $server->execute(ServerRequest::fromString('{"jsonrpc":"2.0","method":"ping","id":1}'))->body,
        );
    }

    public function testReadsTheCurrentRequestWhenNoneIsGiven(): void
    {
        $response = $this->server->execute();

        $this->assertSame('{"jsonrpc":"2.0","error":{"code":-32700,"message":"Parse error"},"id":null}', $response->body);
    }
}
