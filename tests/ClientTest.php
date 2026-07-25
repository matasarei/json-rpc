<?php

declare(strict_types=1);

namespace JsonRPC\Tests;

use JsonRPC\Client;
use JsonRPC\Client\BatchBuilder;
use JsonRPC\Exception\MethodNotFoundException;
use JsonRPC\HttpClient;
use JsonRPC\Tests\Doubles\FakeTransport;
use JsonRPC\Tests\Doubles\SequentialIdGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Client::class)]
final class ClientTest extends TestCase
{
    private FakeTransport $transport;

    private Client $client;

    protected function setUp(): void
    {
        $this->transport = FakeTransport::withJson(['jsonrpc' => '2.0', 'result' => 'foobar', 'id' => 1]);
        $this->client = new Client(
            '',
            new HttpClient('https://example.com/rpc', $this->transport),
            new SequentialIdGenerator(),
        );
    }

    public function testSendsARequestAndReturnsItsResult(): void
    {
        $result = $this->client->execute('methodA', ['a' => 'b']);

        $this->assertSame('foobar', $result);
        $this->assertSame(
            '{"jsonrpc":"2.0","method":"methodA","id":1,"params":{"a":"b"}}',
            $this->transport->lastRequest()->body,
        );
    }

    public function testPassesRequestAttributesIdAndHeaders(): void
    {
        $this->client->execute('methodA', [], ['auth' => 'token'], 'my-id', ['X-Request' => 'yes']);

        $this->assertSame(
            '{"auth":"token","jsonrpc":"2.0","method":"methodA","id":"my-id"}',
            $this->transport->lastRequest()->body,
        );
        $this->assertSame('yes', $this->transport->lastRequest()->headers['X-Request']);
    }

    public function testThrowsTheErrorReturnedByTheServer(): void
    {
        $client = new Client('', new HttpClient('', FakeTransport::withJson([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32601, 'message' => 'Method not found'],
            'id' => 1,
        ])));

        $this->expectException(MethodNotFoundException::class);

        $client->execute('methodA');
    }

    /**
     * Calls a procedure the way a user would, with the name as a method.
     */
    private function magicCall(Client|BatchBuilder $target, string $procedure, mixed ...$arguments): mixed
    {
        return $target->{$procedure}(...$arguments);
    }

    public function testMagicCallsPassASingleArrayAsNamedArguments(): void
    {
        $this->magicCall($this->client, 'methodA', ['a' => 'b']);

        $this->assertSame(
            '{"jsonrpc":"2.0","method":"methodA","id":1,"params":{"a":"b"}}',
            $this->transport->lastRequest()->body,
        );
    }

    public function testMagicCallsPassSeveralArgumentsAsPositionalArguments(): void
    {
        $this->magicCall($this->client, 'methodA', 3, 4);

        $this->assertSame(
            '{"jsonrpc":"2.0","method":"methodA","id":1,"params":[3,4]}',
            $this->transport->lastRequest()->body,
        );
    }

    public function testPositionalModeKeepsASingleArrayArgumentPositional(): void
    {
        $this->magicCall($this->client->withPositionalArguments(), 'methodA', ['a', 'b']);

        $this->assertSame(
            '{"jsonrpc":"2.0","method":"methodA","id":1,"params":[["a","b"]]}',
            $this->transport->lastRequest()->body,
        );
    }

    public function testSendsANotificationWithoutAnId(): void
    {
        $transport = FakeTransport::withBody('', 204);
        $client = new Client('', new HttpClient('', $transport));

        $client->notify('methodA', ['a' => 'b']);

        $this->assertSame('{"jsonrpc":"2.0","method":"methodA","params":{"a":"b"}}', $transport->lastRequest()->body);
    }

    public function testNotificationPayloadHasNoIdMember(): void
    {
        $transport = FakeTransport::withBody('', 204);

        (new Client('', new HttpClient('', $transport)))->notify('methodA', ['a' => 'b'], ['auth' => 'token']);

        $this->assertSame(
            '{"auth":"token","jsonrpc":"2.0","method":"methodA","params":{"a":"b"}}',
            $transport->lastRequest()->body,
        );
    }

    public function testBatchReturnsAFreshBuilderEveryTime(): void
    {
        $first = $this->client->batch();

        $this->assertInstanceOf(BatchBuilder::class, $first);
        $this->assertNotSame($first, $this->client->batch());
    }

    public function testBatchInheritsThePositionalArgumentSetting(): void
    {
        $transport = FakeTransport::withJson([['jsonrpc' => '2.0', 'result' => 'ok', 'id' => 1]]);
        $client = new Client('', new HttpClient('', $transport), new SequentialIdGenerator());

        $batch = $client->withPositionalArguments()->batch();
        $this->magicCall($batch, 'methodA', ['a', 'b']);
        $batch->send();

        $this->assertStringContainsString('"params":[["a","b"]]', $transport->lastRequest()->body);
    }

    public function testBuildsItsOwnHttpClientFromTheUrl(): void
    {
        $client = new Client('https://example.com/rpc');

        $this->assertInstanceOf(HttpClient::class, $client->getHttpClient());
    }

    public function testForwardsCredentialsToTheHttpClient(): void
    {
        $this->client->authentication('user', 'pass');
        $this->client->execute('methodA');

        $this->assertSame(
            'Basic ' . base64_encode('user:pass'),
            $this->transport->lastRequest()->headers['Authorization'],
        );
    }
}
