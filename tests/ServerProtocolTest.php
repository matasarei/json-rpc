<?php

declare(strict_types=1);

namespace JsonRPC\Tests;

use JsonRPC\Server;
use JsonRPC\Server\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The examples of the JSON-RPC 2.0 specification, answered end to end.
 *
 * @link https://www.jsonrpc.org/specification#examples
 */
#[CoversNothing]
final class ServerProtocolTest extends TestCase
{
    private Server $server;

    protected function setUp(): void
    {
        $this->server = new Server();
        $this->server->getProcedureHandler()
            ->withCallback('subtract', fn(int $minuend, int $subtrahend): int => $minuend - $subtrahend)
            ->withCallback('sum', fn(int $a, int $b, int $c): int => $a + $b + $c)
            ->withCallback('update', fn(int $a, int $b, int $c, int $d, int $e): bool => true)
            ->withCallback('get_data', fn(): array => ['hello', 5])
            ->withCallback('notify_hello', fn(int $a): bool => true)
            ->withCallback('notify_sum', fn(int $a, int $b, int $c): int => $a + $b + $c);
    }

    private function call(string $request): string
    {
        return $this->server->execute(ServerRequest::fromString($request))->body;
    }

    public function testCallWithPositionalParameters(): void
    {
        $this->assertSame(
            '{"jsonrpc":"2.0","result":19,"id":1}',
            $this->call('{"jsonrpc": "2.0", "method": "subtract", "params": [42, 23], "id": 1}'),
        );
    }

    public function testCallWithNamedParameters(): void
    {
        $this->assertSame(
            '{"jsonrpc":"2.0","result":19,"id":3}',
            $this->call('{"jsonrpc": "2.0", "method": "subtract", "params": {"subtrahend": 23, "minuend": 42}, "id": 3}'),
        );
    }

    public function testNotification(): void
    {
        $response = $this->server->execute(
            ServerRequest::fromString('{"jsonrpc": "2.0", "method": "notify_hello", "params": [7]}'),
        );

        $this->assertSame('', $response->body);
        $this->assertSame(204, $response->statusCode);
    }

    public function testCallOfNonExistentMethod(): void
    {
        $this->assertSame(
            '{"jsonrpc":"2.0","error":{"code":-32601,"message":"Method not found"},"id":"1"}',
            $this->call('{"jsonrpc": "2.0", "method": "foobar", "id": "1"}'),
        );
    }

    public function testCallWithInvalidJson(): void
    {
        $this->assertSame(
            '{"jsonrpc":"2.0","error":{"code":-32700,"message":"Parse error"},"id":null}',
            $this->call('{"jsonrpc": "2.0", "method": "foobar, "params": "bar", "baz]'),
        );
    }

    public function testCallWithInvalidRequestObject(): void
    {
        $this->assertSame(
            '{"jsonrpc":"2.0","error":{"code":-32600,"message":"Invalid Request"},"id":null}',
            $this->call('{"jsonrpc": "2.0", "method": 1, "params": "bar"}'),
        );
    }

    public function testBatchWithInvalidJson(): void
    {
        $this->assertSame(
            '{"jsonrpc":"2.0","error":{"code":-32700,"message":"Parse error"},"id":null}',
            $this->call('[
                {"jsonrpc": "2.0", "method": "sum", "params": [1,2,4], "id": "1"},
                {"jsonrpc": "2.0", "method"
            ]'),
        );
    }

    public function testCallWithAnEmptyArray(): void
    {
        $this->assertSame(
            '{"jsonrpc":"2.0","error":{"code":-32600,"message":"Invalid Request"},"id":null}',
            $this->call('[]'),
        );
    }

    public function testCallWithAnInvalidBatchOfOne(): void
    {
        $this->assertSame(
            '[{"jsonrpc":"2.0","error":{"code":-32600,"message":"Invalid Request"},"id":null}]',
            $this->call('[1]'),
        );
    }

    public function testCallWithAnInvalidBatch(): void
    {
        $error = '{"jsonrpc":"2.0","error":{"code":-32600,"message":"Invalid Request"},"id":null}';

        $this->assertSame(sprintf('[%s,%s,%s]', $error, $error, $error), $this->call('[1,2,3]'));
    }

    public function testBatch(): void
    {
        $response = $this->call('[
            {"jsonrpc": "2.0", "method": "sum", "params": [1,2,4], "id": "1"},
            {"jsonrpc": "2.0", "method": "notify_hello", "params": [7]},
            {"jsonrpc": "2.0", "method": "subtract", "params": [42,23], "id": "2"},
            {"foo": "boo"},
            {"jsonrpc": "2.0", "method": "foo.get", "params": {"name": "myself"}, "id": "5"},
            {"jsonrpc": "2.0", "method": "get_data", "id": "9"}
        ]');

        $this->assertSame(
            '[' .
            '{"jsonrpc":"2.0","result":7,"id":"1"},' .
            '{"jsonrpc":"2.0","result":19,"id":"2"},' .
            '{"jsonrpc":"2.0","error":{"code":-32600,"message":"Invalid Request"},"id":null},' .
            '{"jsonrpc":"2.0","error":{"code":-32601,"message":"Method not found"},"id":"5"},' .
            '{"jsonrpc":"2.0","result":["hello",5],"id":"9"}' .
            ']',
            $response,
        );
    }

    public function testBatchOfNotifications(): void
    {
        $response = $this->server->execute(ServerRequest::fromString('[
            {"jsonrpc": "2.0", "method": "notify_sum", "params": [1,2,4]},
            {"jsonrpc": "2.0", "method": "notify_hello", "params": [7]}
        ]'));

        $this->assertSame('', $response->body);
        $this->assertSame(204, $response->statusCode);
    }

    public function testCallWithTooManyParameters(): void
    {
        $this->assertSame(
            '{"jsonrpc":"2.0","error":{"code":-32602,"message":"Invalid params"},"id":1}',
            $this->call('{"jsonrpc": "2.0", "method": "subtract", "params": [42, 23, 12], "id": 1}'),
        );
    }

    public function testCallWithAnUnknownNamedParameter(): void
    {
        $this->assertSame(
            '{"jsonrpc":"2.0","error":{"code":-32602,"message":"Invalid params"},"id":1}',
            $this->call('{"jsonrpc": "2.0", "method": "subtract", "params": {"minuend": 42, "foo": 23}, "id": 1}'),
        );
    }
}
