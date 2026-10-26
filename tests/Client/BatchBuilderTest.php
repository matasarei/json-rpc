<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Client;

use JsonRPC\Client;
use JsonRPC\Client\BatchBuilder;
use JsonRPC\Exception\BatchFailedException;
use JsonRPC\Exception\MethodNotFoundException;
use JsonRPC\Exception\ResponseException;
use JsonRPC\HttpClient;
use JsonRPC\Tests\Doubles\FakeTransport;
use JsonRPC\Tests\Doubles\SequentialIdGenerator;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BatchBuilder::class)]
final class BatchBuilderTest extends TestCase
{
    private function client(FakeTransport $transport): Client
    {
        return new Client('', new HttpClient('https://example.com/rpc', $transport), new SequentialIdGenerator());
    }

    public function testSendsEveryCallAsASingleRequestAndReturnsTheResultsInCallOrder(): void
    {
        $transport = FakeTransport::withJson([
            ['jsonrpc' => '2.0', 'result' => 'b', 'id' => 2],
            ['jsonrpc' => '2.0', 'result' => 'a', 'id' => 1],
        ]);

        $results = $this->client($transport)->batch()
            ->execute('methodA')
            ->execute('methodB')
            ->send();

        $this->assertSame(['a', 'b'], $results);
        $this->assertSame(
            '[{"jsonrpc":"2.0","method":"methodA","id":1},{"jsonrpc":"2.0","method":"methodB","id":2}]',
            $transport->lastRequest()->body,
        );
    }

    /**
     * Calls a procedure the way a user would, with the name as a method.
     */
    private function magicCall(BatchBuilder $batch, string $procedure, mixed ...$arguments): BatchBuilder
    {
        $result = $batch->{$procedure}(...$arguments);

        $this->assertInstanceOf(BatchBuilder::class, $result);

        return $result;
    }

    public function testSupportsMagicCalls(): void
    {
        $transport = FakeTransport::withJson([['jsonrpc' => '2.0', 'result' => 'a', 'id' => 1]]);

        $results = $this->magicCall($this->client($transport)->batch(), 'methodA', ['x' => 'y'])->send();

        $this->assertSame(['a'], $results);
        $this->assertStringContainsString('"params":{"x":"y"}', $transport->lastRequest()->body);
    }

    public function testMagicCallsWithSeveralArgumentsStayPositional(): void
    {
        $transport = FakeTransport::withJson([['jsonrpc' => '2.0', 'result' => 'a', 'id' => 1]]);

        $this->magicCall($this->client($transport)->batch(), 'methodA', 3, 4)->send();

        $this->assertStringContainsString('"params":[3,4]', $transport->lastRequest()->body);
    }

    public function testNotificationsTakeNoResultSlot(): void
    {
        $transport = FakeTransport::withJson([['jsonrpc' => '2.0', 'result' => 'a', 'id' => 1]]);

        $results = $this->client($transport)->batch()
            ->execute('methodA')
            ->notify('methodB')
            ->send();

        $this->assertSame(['a'], $results);
        $this->assertStringContainsString('{"jsonrpc":"2.0","method":"methodB"}', $transport->lastRequest()->body);
    }

    public function testABatchOfNotificationsIsSentAndReturnsNoResult(): void
    {
        $transport = FakeTransport::withBody('', 204);

        $results = $this->client($transport)->batch()
            ->notify('methodA')
            ->notify('methodB')
            ->send();

        $this->assertSame([], $results);
        $this->assertSame(
            '[{"jsonrpc":"2.0","method":"methodA"},{"jsonrpc":"2.0","method":"methodB"}]',
            $transport->lastRequest()->body,
        );
    }

    public function testAnEmptyBatchSendsNothing(): void
    {
        $transport = FakeTransport::withJson([]);

        $this->assertSame([], $this->client($transport)->batch()->send());
        $this->assertSame([], $transport->requests);
    }

    public function testPassesPerRequestHeaders(): void
    {
        $transport = FakeTransport::withJson([['jsonrpc' => '2.0', 'result' => 'a', 'id' => 1]]);

        $this->client($transport)->batch()->execute('methodA')->send(['X-Batch' => 'yes']);

        $this->assertSame('yes', $transport->lastRequest()->headers['X-Batch']);
    }

    public function testKeepsExplicitIdsAndRequestAttributes(): void
    {
        $transport = FakeTransport::withJson([['jsonrpc' => '2.0', 'result' => 'a', 'id' => 'mine']]);

        $results = $this->client($transport)->batch()
            ->execute('methodA', ['p' => 1], ['auth' => 'token'], 'mine')
            ->send();

        $this->assertSame(['a'], $results);
        $this->assertStringContainsString('"auth":"token"', $transport->lastRequest()->body);
        $this->assertStringContainsString('"id":"mine"', $transport->lastRequest()->body);
    }

    public function testFailedCallsAreReportedWithTheResultsThatSucceeded(): void
    {
        $transport = FakeTransport::withJson([
            ['jsonrpc' => '2.0', 'result' => 'a', 'id' => 1],
            ['jsonrpc' => '2.0', 'error' => ['code' => -32601, 'message' => 'Method not found'], 'id' => 2],
        ]);

        try {
            $this->client($transport)->batch()->execute('methodA')->execute('missing')->send();
            $this->fail('An exception should have been thrown');
        } catch (BatchFailedException $exception) {
            $this->assertSame('1 of 2 calls failed', $exception->getMessage());
            $this->assertSame([0 => 'a'], $exception->getResults());
            $this->assertInstanceOf(MethodNotFoundException::class, $exception->getErrors()[1]);
        }
    }

    public function testMissingAnswersAreReportedAsErrors(): void
    {
        $transport = FakeTransport::withJson([['jsonrpc' => '2.0', 'result' => 'a', 'id' => 1]]);

        try {
            $this->client($transport)->batch()->execute('methodA')->execute('methodB')->send();
            $this->fail('An exception should have been thrown');
        } catch (BatchFailedException $exception) {
            $this->assertInstanceOf(ResponseException::class, $exception->getErrors()[1]);
        }
    }

    public function testABatchCannotBeSentTwice(): void
    {
        $transport = FakeTransport::withJson([['jsonrpc' => '2.0', 'result' => 'a', 'id' => 1]]);
        $batch = $this->client($transport)->batch()->execute('methodA');
        $batch->send();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('This batch was already sent');

        $batch->send();
    }
}
