<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Transport;

use InvalidArgumentException;
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

    public function testRefusesHeadersCarryingALineBreak(): void
    {
        foreach ([['X-Trace' => "one\r\nX-Injected: yes"], ["X-Bad\r\nX-Injected" => 'yes']] as $headers) {
            try {
                new TransportRequest('https://example.com/rpc', '', $headers);
                $this->fail('An exception should have been thrown');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('contains a line break', $exception->getMessage());
            }
        }
    }

    public function testHeadersAreEmptyByDefault(): void
    {
        $request = new TransportRequest('https://example.com/rpc', '');

        $this->assertSame([], $request->headers);
        $this->assertSame([], $request->headerLines());
    }
}
