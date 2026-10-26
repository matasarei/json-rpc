<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Transport;

use InvalidArgumentException;
use JsonRPC\Exception\ConnectionFailureException;
use JsonRPC\Tests\Doubles\FailingPsr18Client;
use JsonRPC\Tests\Doubles\RecordingPsr18Client;
use JsonRPC\Transport\Psr18Transport;
use JsonRPC\Transport\TransportRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Psr18Transport::class)]
final class Psr18TransportTest extends TestCase
{
    public function testSendsThePayloadThroughThePsr18Client(): void
    {
        $client = new RecordingPsr18Client(200, '{"jsonrpc":"2.0","result":"pong","id":1}', [
            'Content-Type' => 'application/json',
            'Set-Cookie' => ['a=1', 'b=2'],
        ]);

        $response = (new Psr18Transport($client))->send(new TransportRequest(
            ' https://example.com/rpc ',
            '{"jsonrpc":"2.0","method":"ping","id":1}',
            ['Content-Type' => 'application/json', 'X-Custom' => 'value'],
        ));

        $this->assertNotNull($client->lastRequest);
        $this->assertSame('POST', $client->lastRequest->getMethod());
        $this->assertSame('https://example.com/rpc', (string) $client->lastRequest->getUri());
        $this->assertSame('{"jsonrpc":"2.0","method":"ping","id":1}', (string) $client->lastRequest->getBody());
        $this->assertSame('value', $client->lastRequest->getHeaderLine('X-Custom'));

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('{"jsonrpc":"2.0","result":"pong","id":1}', $response->body);
        $this->assertSame(['application/json'], $response->headerValues('Content-Type'));
        $this->assertSame(['a=1', 'b=2'], $response->headerValues('Set-Cookie'));
    }

    public function testAcceptsSeparatePsr17Factories(): void
    {
        $factory = new Psr17Factory();

        $response = (new Psr18Transport(new RecordingPsr18Client(204), $factory, $factory))
            ->send(new TransportRequest('https://example.com/rpc', ''));

        $this->assertSame(204, $response->statusCode);
    }

    public function testTurnsClientFailuresIntoConnectionFailures(): void
    {
        $factory = new Psr17Factory();
        $transport = new Psr18Transport(new FailingPsr18Client(), $factory, $factory);

        $this->expectException(ConnectionFailureException::class);
        $this->expectExceptionMessage('Unable to establish a connection: Name or service not known');

        $transport->send(new TransportRequest('https://example.com/rpc', ''));
    }

    public function testRequiresARequestFactoryTheClientDoesNotProvide(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PSR-17 request factory is required');

        new Psr18Transport(new FailingPsr18Client());
    }

    public function testRequiresAStreamFactoryTheClientDoesNotProvide(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PSR-17 stream factory is required');

        new Psr18Transport(new FailingPsr18Client(), new Psr17Factory());
    }
}
