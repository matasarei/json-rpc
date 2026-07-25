<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Transport;

use JsonRPC\Exception\ConnectionFailureException;
use JsonRPC\Tests\Support\TestHttpServer;
use JsonRPC\Transport\CurlTransport;
use JsonRPC\Transport\TransportOptions;
use JsonRPC\Transport\TransportRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[CoversClass(CurlTransport::class)]
#[RequiresPhpExtension('curl')]
final class CurlTransportTest extends TestCase
{
    private static TestHttpServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = TestHttpServer::instance();
    }

    public function testPostsThePayloadAndReadsTheResponse(): void
    {
        $transport = new CurlTransport();

        $response = $transport->send(new TransportRequest(
            self::$server->url('/echo'),
            '{"jsonrpc":"2.0","method":"ping","id":1}',
            ['Content-Type' => 'application/json', 'X-Custom' => 'value'],
        ));

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['application/json'], $response->headerValues('Content-Type'));

        /** @var array{result: array{method: string, body: string, headers: array<string, string>}} $payload */
        $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('POST', $payload['result']['method']);
        $this->assertSame('{"jsonrpc":"2.0","method":"ping","id":1}', $payload['result']['body']);
        $this->assertSame('value', $payload['result']['headers']['x-custom']);
    }

    public function testReportsErrorStatusCodesInsteadOfFailing(): void
    {
        $response = (new CurlTransport())->send(new TransportRequest(self::$server->url('/status/500'), ''));

        $this->assertSame(500, $response->statusCode);
        $this->assertStringContainsString('"result":"status"', $response->body);
    }

    public function testDoesNotFollowRedirects(): void
    {
        $response = (new CurlTransport())->send(new TransportRequest(self::$server->url('/redirect'), ''));

        $this->assertSame(302, $response->statusCode);
    }

    public function testCollectsEveryResponseHeader(): void
    {
        $response = (new CurlTransport())->send(new TransportRequest(self::$server->url('/cookies'), ''));

        $this->assertSame(['session=abc=def; Path=/; HttpOnly', 'theme=dark; Path=/'], $response->headerValues('Set-Cookie'));
    }

    public function testFailsWhenTheConnectionCannotBeEstablished(): void
    {
        $transport = new CurlTransport();

        $this->expectException(ConnectionFailureException::class);
        $this->expectExceptionMessage('Unable to establish a connection:');

        $transport->send(new TransportRequest('http://127.0.0.1:1/rpc', ''));
    }

    public function testReportsATimeoutWithItsOwnMessage(): void
    {
        $transport = new CurlTransport(new TransportOptions(timeout: 1));

        $this->expectException(ConnectionFailureException::class);
        $this->expectExceptionMessage('Operation timed out');

        $transport->send(new TransportRequest(self::$server->url('/slow'), ''));
    }

    public function testBuildsTheDefaultOptions(): void
    {
        $options = (new CurlTransport())->buildOptions(
            new TransportRequest(' https://example.com/rpc ', 'payload', ['Accept' => 'application/json']),
        );

        $this->assertSame('https://example.com/rpc', $options[CURLOPT_URL]);
        $this->assertTrue($options[CURLOPT_RETURNTRANSFER]);
        $this->assertTrue($options[CURLOPT_POST]);
        $this->assertSame('payload', $options[CURLOPT_POSTFIELDS]);
        $this->assertSame(['Accept: application/json'], $options[CURLOPT_HTTPHEADER]);
        $this->assertSame(5, $options[CURLOPT_CONNECTTIMEOUT]);
        $this->assertSame(0, $options[CURLOPT_TIMEOUT]);
        $this->assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        $this->assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        $this->assertArrayNotHasKey(CURLOPT_CAINFO, $options);
        $this->assertArrayNotHasKey(CURLOPT_SSLCERT, $options);
    }

    public function testMapsTheSslAndTimeoutSettings(): void
    {
        $transport = new CurlTransport(new TransportOptions(
            connectTimeout: 2,
            timeout: 30,
            verifySsl: false,
            caFile: '/ca.pem',
            localCert: '/client.pem',
        ));

        $options = $transport->buildOptions(new TransportRequest('https://example.com/rpc', ''));

        $this->assertSame(2, $options[CURLOPT_CONNECTTIMEOUT]);
        $this->assertSame(30, $options[CURLOPT_TIMEOUT]);
        $this->assertFalse($options[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(0, $options[CURLOPT_SSL_VERIFYHOST]);
        $this->assertSame('/ca.pem', $options[CURLOPT_CAINFO]);
        $this->assertSame('/client.pem', $options[CURLOPT_SSLCERT]);
    }

    public function testExtraOptionsWinOverTheDefaults(): void
    {
        $transport = new CurlTransport(new TransportOptions(extraOptions: [CURLOPT_TIMEOUT => 99]));

        $options = $transport->buildOptions(new TransportRequest('https://example.com/rpc', ''));

        $this->assertSame(99, $options[CURLOPT_TIMEOUT]);
    }
}
