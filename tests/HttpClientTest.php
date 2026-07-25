<?php

declare(strict_types=1);

namespace JsonRPC\Tests;

use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\Exception\ConnectionFailureException;
use JsonRPC\Exception\ResponseException;
use JsonRPC\Exception\ServerErrorException;
use JsonRPC\HttpClient;
use JsonRPC\Tests\Doubles\FakeTransport;
use JsonRPC\Tests\Doubles\RecordingTransportFactory;
use JsonRPC\Tests\Doubles\SpyLogger;
use JsonRPC\Transport\CookieJar;
use JsonRPC\Transport\CurlTransport;
use JsonRPC\Transport\TransportResponse;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpClient::class)]
final class HttpClientTest extends TestCase
{
    public function testSendsThePayloadToTheConfiguredUrlAndDecodesTheAnswer(): void
    {
        $transport = FakeTransport::withJson(['jsonrpc' => '2.0', 'result' => 'pong', 'id' => 1]);
        $client = new HttpClient('https://example.com/rpc', $transport);

        $result = $client->execute('{"jsonrpc":"2.0","method":"ping","id":1}');

        $this->assertSame(['jsonrpc' => '2.0', 'result' => 'pong', 'id' => 1], $result);
        $this->assertSame('https://example.com/rpc', $transport->lastRequest()->url);
        $this->assertSame('{"jsonrpc":"2.0","method":"ping","id":1}', $transport->lastRequest()->body);
    }

    public function testSendsTheDefaultHeaders(): void
    {
        $transport = FakeTransport::withJson([]);

        (new HttpClient('https://example.com/rpc', $transport))->execute('{}');

        $this->assertSame([
            'User-Agent' => 'JSON-RPC PHP Client <https://github.com/matasarei/json-rpc>',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Connection' => 'close',
        ], $transport->lastRequest()->headers);
    }

    public function testMergesInstanceAndPerRequestHeaders(): void
    {
        $transport = FakeTransport::withJson([]);
        $client = (new HttpClient('https://example.com/rpc', $transport))->withHeaders(['X-Instance' => 'a']);

        $client->execute('{}', ['X-Request' => 'b', 'Accept' => 'application/vnd.custom']);

        $headers = $transport->lastRequest()->headers;
        $this->assertSame('a', $headers['X-Instance']);
        $this->assertSame('b', $headers['X-Request']);
        $this->assertSame('application/vnd.custom', $headers['Accept']);
    }

    public function testSendsBasicAuthenticationOnlyWhenBothCredentialsAreSet(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(200, '{}'),
            new TransportResponse(200, '{}'),
        );

        $client = (new HttpClient('https://example.com/rpc', $transport))->withUsername('user');
        $client->execute('{}');
        $this->assertArrayNotHasKey('Authorization', $transport->lastRequest()->headers);

        $client->withPassword('pass')->execute('{}');
        $this->assertSame('Basic ' . base64_encode('user:pass'), $transport->lastRequest()->headers['Authorization']);
    }

    public function testSendsAndCollectsCookies(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(200, '{}', ['set-cookie' => ['session=abc=def; Path=/', 'theme=dark']]),
            new TransportResponse(200, '{}'),
        );
        $client = new HttpClient('https://example.com/rpc', $transport);

        $client->execute('{}');
        $this->assertSame(['session' => 'abc=def', 'theme' => 'dark'], $client->getCookies());

        $client->execute('{}');
        $this->assertSame('session=abc=def; theme=dark', $transport->lastRequest()->headers['Cookie']);
    }

    public function testCookiesCanBeSeededAndReplaced(): void
    {
        $transport = FakeTransport::withJson([]);
        $client = new HttpClient('https://example.com/rpc', $transport);

        $client->withCookies(['a' => '1']);
        $client->withCookies(['b' => '2']);
        $this->assertSame(['a' => '1', 'b' => '2'], $client->getCookies());

        $client->withCookies(['c' => '3'], true);
        $this->assertSame(['c' => '3'], $client->getCookies());
    }

    public function testAcceptsAPreExistingCookieJar(): void
    {
        $jar = new CookieJar(['session' => 'abc']);
        $transport = FakeTransport::withJson([]);

        (new HttpClient('https://example.com/rpc', $transport, $jar))->execute('{}');

        $this->assertSame('session=abc', $transport->lastRequest()->headers['Cookie']);
    }

    public function testCallsTheBeforeRequestCallbackWithTheRequestData(): void
    {
        $transport = FakeTransport::withJson([]);
        $seen = [];

        $client = (new HttpClient('https://example.com/rpc', $transport))
            ->withBeforeRequestCallback(function (HttpClient $client, string $payload, array $headers) use (&$seen): void {
                $seen = ['payload' => $payload, 'headers' => $headers];
                $client->withHeaders(['Content-Length' => (string) strlen($payload)]);
            });

        $client->execute('{"a":1}', ['X-Request' => 'b']);

        $this->assertSame(['payload' => '{"a":1}', 'headers' => ['X-Request' => 'b']], $seen);
        $this->assertSame('7', $transport->lastRequest()->headers['Content-Length']);
    }

    public function testReturnsNullForAnEmptyBody(): void
    {
        $client = new HttpClient('https://example.com/rpc', FakeTransport::withBody('', 204));

        $this->assertNull($client->execute('{}'));
    }

    public function testReturnsNullWhenTheBodyIsNotJson(): void
    {
        $client = new HttpClient('https://example.com/rpc', FakeTransport::withBody('not json'));

        $this->assertNull($client->execute('{}'));
    }

    /**
     * @return list<array{int, class-string<\Throwable>}>
     */
    public static function failingStatusCodes(): array
    {
        return [
            [401, AccessDeniedException::class],
            [403, AccessDeniedException::class],
            [404, ConnectionFailureException::class],
            [500, ServerErrorException::class],
        ];
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('failingStatusCodes')]
    public function testMapsTheDocumentedStatusCodesToExceptions(int $statusCode, string $exception): void
    {
        $client = new HttpClient('https://example.com/rpc', FakeTransport::withJson([], $statusCode));

        $this->expectException($exception);
        $this->expectExceptionMessage(sprintf('Response with status code %d', $statusCode));

        $client->execute('{}');
    }

    public function testReportsRedirectsBecauseTheyAreNeverFollowed(): void
    {
        $client = new HttpClient('https://example.com/rpc', FakeTransport::withBody('', 302));

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('Unexpected response with status code 302');

        $client->execute('{}');
    }

    public function testReportsUnexpectedErrorStatusCodesWithoutAJsonBody(): void
    {
        $client = new HttpClient('https://example.com/rpc', FakeTransport::withBody('too many requests', 429));

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('Unexpected response with status code 429');

        $client->execute('{}');
    }

    public function testKeepsErrorObjectsAnsweredWithAnErrorStatusCode(): void
    {
        $payload = ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Invalid Request'], 'id' => null];
        $client = new HttpClient('https://example.com/rpc', FakeTransport::withJson($payload, 429));

        $this->assertSame($payload, $client->execute('{}'));
    }

    public function testAcceptsUnusualButSuccessfulStatusCodes(): void
    {
        $client = new HttpClient('https://example.com/rpc', FakeTransport::withBody('', 299));

        $this->assertNull($client->execute('{}'));
    }

    public function testAcceptsStatusCodesOutsideTheErrorRange(): void
    {
        $client = new HttpClient('https://example.com/rpc', FakeTransport::withBody('', 600));

        $this->assertNull($client->execute('{}'));
    }

    public function testPropagatesTransportFailures(): void
    {
        $client = new HttpClient('https://example.com/rpc', FakeTransport::failing('Operation timed out'));

        $this->expectException(ConnectionFailureException::class);
        $this->expectExceptionMessage('Operation timed out');

        $client->execute('{}');
    }

    public function testUrlCanBeChangedAfterConstruction(): void
    {
        $transport = FakeTransport::withJson([]);

        (new HttpClient('', $transport))->withUrl('https://example.com/other')->execute('{}');

        $this->assertSame('https://example.com/other', $transport->lastRequest()->url);
    }

    public function testLogsTheRequestAndTheResponseWithCredentialsRedacted(): void
    {
        $logger = new SpyLogger();
        $transport = FakeTransport::withJson(
            ['jsonrpc' => '2.0', 'result' => 'pong', 'id' => 1],
            200,
            ['set-cookie' => ['session=secret'], 'content-type' => ['application/json']],
        );

        (new HttpClient('https://example.com/rpc', $transport))
            ->withUsername('user')
            ->withPassword('pass')
            ->withCookies(['session' => 'secret'])
            ->withLogger($logger)
            ->execute('{"jsonrpc":"2.0","method":"ping","id":1}');

        $request = $logger->contextOf('Request');
        $this->assertSame('https://example.com/rpc', $request['url']);
        $this->assertSame('{"jsonrpc":"2.0","method":"ping","id":1}', $request['payload']);
        $this->assertSame('[redacted]', $request['headers']['Authorization']);
        $this->assertSame('[redacted]', $request['headers']['Cookie']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);

        $response = $logger->contextOf('Response');
        $this->assertSame(200, $response['status']);
        $this->assertSame('[redacted]', $response['headers']['set-cookie']);
        $this->assertSame(['application/json'], $response['headers']['content-type']);
        $this->assertStringContainsString('pong', $response['payload']);
    }

    public function testLogsNothingWithoutALogger(): void
    {
        $logger = new SpyLogger();
        $client = new HttpClient('https://example.com/rpc', FakeTransport::withJson([]));

        $client->execute('{}');

        $this->assertSame([], $logger->records);
    }

    public function testAsksTheFactoryForATransportOnceAndReusesIt(): void
    {
        $factory = new RecordingTransportFactory(new FakeTransport(
            new TransportResponse(200, '{}'),
            new TransportResponse(200, '{}'),
        ));
        $client = new HttpClient('https://example.com/rpc', null, new CookieJar(), $factory);

        $client->execute('{}');
        $client->execute('{}');

        $this->assertSame(1, $factory->calls);
    }

    public function testAppliesConnectionSettingsToTheDefaultTransport(): void
    {
        $factory = new RecordingTransportFactory(FakeTransport::withJson([]));

        (new HttpClient('https://example.com/rpc', null, new CookieJar(), $factory))
            ->withTimeout(1)
            ->withExecutionTimeout(2)
            ->withoutSslVerification()
            ->withCaFile('/ca.pem')
            ->withLocalCert('/client.pem')
            ->withTransportOptions([99 => 'raw'])
            ->execute('{}');

        $options = $factory->usedOptions();
        $this->assertSame(1, $options->connectTimeout);
        $this->assertSame(2, $options->timeout);
        $this->assertFalse($options->verifySsl);
        $this->assertSame('/ca.pem', $options->caFile);
        $this->assertSame('/client.pem', $options->localCert);
        $this->assertSame([99 => 'raw'], $options->extraOptions);
    }

    public function testUsesTheRealFactoryByDefault(): void
    {
        $client = new HttpClient('http://127.0.0.1:1/rpc');

        $this->expectException(ConnectionFailureException::class);

        $client->execute('{}');
    }

    public function testRejectsConnectionSettingsWhenATransportIsInjected(): void
    {
        $client = new HttpClient('https://example.com/rpc', new CurlTransport());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Connection settings do not apply to an injected transport');

        $client->withTimeout(10);
    }
}
