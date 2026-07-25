<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Transport;

use JsonRPC\Transport\TransportResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransportResponse::class)]
final class TransportResponseTest extends TestCase
{
    public function testKeepsStatusBodyAndHeaders(): void
    {
        $response = new TransportResponse(200, 'body', ['content-type' => ['application/json']]);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('body', $response->body);
        $this->assertSame(['content-type' => ['application/json']], $response->headers);
        $this->assertSame([], (new TransportResponse(200, ''))->headers);
    }

    public function testHeaderLookupIsCaseInsensitiveAndReturnsEveryValue(): void
    {
        $response = TransportResponse::fromRawHeaders('body', [
            'HTTP/1.1 200 OK',
            'Content-Type: application/json',
            'Set-Cookie: a=1',
            'Set-Cookie: b=2',
        ]);

        $this->assertSame(['application/json'], $response->headerValues('CONTENT-TYPE'));
        $this->assertSame(['a=1', 'b=2'], $response->headerValues('Set-Cookie'));
        $this->assertSame([], $response->headerValues('X-Missing'));
    }

    public function testReadsTheStatusCodeFromTheStatusLine(): void
    {
        $response = TransportResponse::fromRawHeaders('', ['HTTP/2 404']);

        $this->assertSame(404, $response->statusCode);
    }

    public function testPrefersTheStatusCodeGivenByTheTransport(): void
    {
        $response = TransportResponse::fromRawHeaders('', ['HTTP/1.1 200 OK'], 418);

        $this->assertSame(418, $response->statusCode);
    }

    public function testFallsBackToZeroWithoutAnyStatusLine(): void
    {
        $this->assertSame(0, TransportResponse::fromRawHeaders('', ['Content-Type: text/plain'])->statusCode);
    }

    public function testKeepsOnlyTheHeadersOfTheLastResponse(): void
    {
        $response = TransportResponse::fromRawHeaders('body', [
            'HTTP/1.1 100 Continue',
            'X-Intermediate: dropped',
            'HTTP/1.1 200 OK',
            'X-Final: kept',
        ]);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame([], $response->headerValues('X-Intermediate'));
        $this->assertSame(['kept'], $response->headerValues('X-Final'));
    }

    public function testIgnoresLinesThatAreNotHeaders(): void
    {
        $response = TransportResponse::fromRawHeaders('', ['HTTP/1.1 200 OK', 'garbage', 'X-Real: value']);

        $this->assertSame(['x-real' => ['value']], $response->headers);
    }

    public function testKeepsColonsInsideHeaderValues(): void
    {
        $response = TransportResponse::fromRawHeaders('', ['HTTP/1.1 200 OK', 'X-Time: 10:30:00']);

        $this->assertSame(['10:30:00'], $response->headerValues('X-Time'));
    }
}
