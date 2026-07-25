<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Transport;

use JsonRPC\Transport\TransportRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransportRequest::class)]
final class TransportRequestTest extends TestCase
{
    public function testKeepsUrlBodyAndHeaders(): void
    {
        $request = new TransportRequest('https://example.com/rpc', '{"jsonrpc":"2.0"}', ['Accept' => 'application/json']);

        $this->assertSame('https://example.com/rpc', $request->url);
        $this->assertSame('{"jsonrpc":"2.0"}', $request->body);
        $this->assertSame(['Accept' => 'application/json'], $request->headers);
    }

    public function testRendersHeadersAsLines(): void
    {
        $request = new TransportRequest('https://example.com/rpc', '', [
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic dXNlcjpwYXNz',
        ]);

        $this->assertSame(
            ['Content-Type: application/json', 'Authorization: Basic dXNlcjpwYXNz'],
            $request->headerLines(),
        );
    }

    public function testHeadersAreEmptyByDefault(): void
    {
        $request = new TransportRequest('https://example.com/rpc', '');

        $this->assertSame([], $request->headers);
        $this->assertSame([], $request->headerLines());
    }
}
