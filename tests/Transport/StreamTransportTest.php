<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Transport;

use JsonRPC\Exception\ConnectionFailureException;
use JsonRPC\Tests\Support\TestHttpServer;
use JsonRPC\Transport\StreamTransport;
use JsonRPC\Transport\TransportOptions;
use JsonRPC\Transport\TransportRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreamTransport::class)]
final class StreamTransportTest extends TestCase
{
    private static TestHttpServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = TestHttpServer::instance();
    }

    public function testPostsThePayloadAndReadsTheResponse(): void
    {
        $response = (new StreamTransport())->send(new TransportRequest(
            self::$server->url('/echo'),
            '{"jsonrpc":"2.0","method":"ping","id":1}',
            ['Content-Type' => 'application/json', 'X-Custom' => 'value'],
        ));

        $this->assertSame(200, $response->statusCode);

        /** @var array{result: array{method: string, body: string, headers: array<string, string>}} $payload */
        $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('POST', $payload['result']['method']);
        $this->assertSame('{"jsonrpc":"2.0","method":"ping","id":1}', $payload['result']['body']);
        $this->assertSame('value', $payload['result']['headers']['x-custom']);
    }

    public function testReadsTheBodyOfErrorResponses(): void
    {
        $response = (new StreamTransport())->send(new TransportRequest(self::$server->url('/status/500'), ''));

        $this->assertSame(500, $response->statusCode);
        $this->assertStringContainsString('"result":"status"', $response->body);
    }

    public function testDoesNotFollowRedirects(): void
    {
        $response = (new StreamTransport())->send(new TransportRequest(self::$server->url('/redirect'), ''));

        $this->assertSame(302, $response->statusCode);
    }

    public function testCollectsEveryResponseHeader(): void
    {
        $response = (new StreamTransport())->send(new TransportRequest(self::$server->url('/cookies'), ''));

        $this->assertSame(['session=abc=def; Path=/; HttpOnly', 'theme=dark; Path=/'], $response->headerValues('Set-Cookie'));
    }

    public function testFailsWhenTheConnectionCannotBeEstablished(): void
    {
        $transport = new StreamTransport();

        $this->expectException(ConnectionFailureException::class);
        $this->expectExceptionMessage('Unable to establish a connection:');

        $transport->send(new TransportRequest('http://127.0.0.1:1/rpc', ''));
    }

    public function testReportsAnUnusableUrlWithoutLeavingItsErrorHandlerBehind(): void
    {
        $handler = static fn(): bool => true;
        set_error_handler($handler);

        try {
            (new StreamTransport())->send(new TransportRequest('', ''));
            $this->fail('An exception should have been thrown');
        } catch (ConnectionFailureException $exception) {
            $this->assertStringContainsString('Unable to establish a connection', $exception->getMessage());
            // The handler on top of the stack has to be the one installed here,
            // otherwise the transport left its own behind and the application
            // stops seeing its own errors.
            $this->assertSame($handler, set_error_handler(null));
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
    }

    public function testBuildsTheDefaultContextOptions(): void
    {
        $options = (new StreamTransport())->buildContextOptions(
            new TransportRequest('https://example.com/rpc', 'payload', ['Accept' => 'application/json']),
        );

        $this->assertSame('POST', $options['http']['method']);
        $this->assertSame(1.1, $options['http']['protocol_version']);
        $this->assertSame('Accept: application/json', $options['http']['header']);
        $this->assertSame('payload', $options['http']['content']);
        $this->assertTrue($options['http']['ignore_errors']);
        $this->assertSame(0, $options['http']['follow_location']);
        $this->assertSame(1, $options['http']['max_redirects']);
        $this->assertTrue($options['ssl']['verify_peer']);
        $this->assertTrue($options['ssl']['verify_peer_name']);
        $this->assertArrayNotHasKey('cafile', $options['ssl']);
        $this->assertArrayNotHasKey('local_cert', $options['ssl']);
    }

    public function testUsesTheConnectTimeoutWhenNoTransferTimeoutIsSet(): void
    {
        $options = (new StreamTransport(new TransportOptions(connectTimeout: 7)))
            ->buildContextOptions(new TransportRequest('https://example.com/rpc', ''));

        $this->assertSame(7, $options['http']['timeout']);
    }

    public function testPrefersTheTransferTimeoutWhenSet(): void
    {
        $options = (new StreamTransport(new TransportOptions(connectTimeout: 7, transferTimeout: 30)))
            ->buildContextOptions(new TransportRequest('https://example.com/rpc', ''));

        $this->assertSame(30, $options['http']['timeout']);
    }

    public function testMapsTheSslSettings(): void
    {
        $options = (new StreamTransport(new TransportOptions(
            verifySsl: false,
            caFile: '/ca.pem',
            localCert: '/client.pem',
        )))->buildContextOptions(new TransportRequest('https://example.com/rpc', ''));

        $this->assertFalse($options['ssl']['verify_peer']);
        $this->assertFalse($options['ssl']['verify_peer_name']);
        $this->assertSame('/ca.pem', $options['ssl']['cafile']);
        $this->assertSame('/client.pem', $options['ssl']['local_cert']);
    }

    public function testExtraOptionsWinOverTheDefaults(): void
    {
        $options = (new StreamTransport(new TransportOptions(extraOptions: ['http' => ['user_agent' => 'test']])))
            ->buildContextOptions(new TransportRequest('https://example.com/rpc', ''));

        $this->assertSame('test', $options['http']['user_agent']);
        $this->assertSame('POST', $options['http']['method']);
    }
}
