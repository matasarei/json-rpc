<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Server;

use JsonRPC\Server\ServerResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServerResponse::class)]
final class ServerResponseTest extends TestCase
{
    public function testAnswersJsonWithStatusCode200ByDefault(): void
    {
        $response = new ServerResponse('{"jsonrpc":"2.0"}');

        $this->assertSame('{"jsonrpc":"2.0"}', $response->body);
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['Content-Type' => 'application/json'], $response->headers);
    }

    public function testWritesTheStatusHeadersAndBodyOut(): void
    {
        $headers = [];
        $status = null;
        $response = new ServerResponse('{"result":1}', 401, [
            'Content-Type' => 'application/json',
            'WWW-Authenticate' => 'Basic realm="JsonRPC"',
        ]);

        ob_start();
        $response->send(
            function (string $header) use (&$headers): void {
                $headers[] = $header;
            },
            function (int $code) use (&$status): void {
                $status = $code;
            },
        );
        $body = ob_get_clean();

        $this->assertSame(401, $status);
        $this->assertSame(
            ['Content-Type: application/json', 'WWW-Authenticate: Basic realm="JsonRPC"'],
            $headers,
        );
        $this->assertSame('{"result":1}', $body);
    }

    #[RunInSeparateProcess]
    public function testWritesThroughTheNativeFunctionsByDefault(): void
    {
        ob_start();
        (new ServerResponse('{"result":1}', 204, []))->send();
        $body = ob_get_clean();

        $this->assertSame('{"result":1}', $body);
        $this->assertSame(204, http_response_code());
    }
}
