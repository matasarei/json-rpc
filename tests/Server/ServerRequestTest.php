<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Server;

use JsonRPC\Server\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServerRequest::class)]
final class ServerRequestTest extends TestCase
{
    public function testCarriesTheBodyAndTheServerVariables(): void
    {
        $request = ServerRequest::fromString('{"jsonrpc":"2.0"}', ['REMOTE_ADDR' => '127.0.0.1']);

        $this->assertSame('{"jsonrpc":"2.0"}', $request->body);
        $this->assertSame(['REMOTE_ADDR' => '127.0.0.1'], $request->serverVariables);
        $this->assertSame('127.0.0.1', $request->remoteAddress());
        $this->assertSame('127.0.0.1', $request->serverVariable('REMOTE_ADDR'));
    }

    public function testReportsMissingOrUnusableServerVariablesAsNull(): void
    {
        $request = ServerRequest::fromString('', ['WEIRD' => ['an', 'array']]);

        $this->assertNull($request->remoteAddress());
        $this->assertNull($request->serverVariable('MISSING'));
        $this->assertNull($request->serverVariable('WEIRD'));
    }

    public function testReadsTheStandardCredentials(): void
    {
        $request = ServerRequest::fromString('', ['PHP_AUTH_USER' => 'user', 'PHP_AUTH_PW' => 'pass']);

        $this->assertSame(['user', 'pass'], $request->credentials());
    }

    public function testKeepsCredentialsMadeOfTheStringZero(): void
    {
        $request = ServerRequest::fromString('', ['PHP_AUTH_USER' => '0', 'PHP_AUTH_PW' => '0']);

        $this->assertSame(['0', '0'], $request->credentials());
    }

    public function testReportsMissingCredentialsAsNull(): void
    {
        $this->assertSame([null, null], ServerRequest::fromString('')->credentials());
    }

    public function testReadsCredentialsFromAnAlternativeHeader(): void
    {
        $request = ServerRequest::fromString('', [
            'HTTP_X_AUTH' => base64_encode('user:pass:with:colons'),
            'PHP_AUTH_USER' => 'ignored',
        ]);

        $this->assertSame(['user', 'pass:with:colons'], $request->credentials('X-Auth'));
    }

    public function testReadsAnAlternativeHeaderForwardedWithItsScheme(): void
    {
        foreach (['Basic ', 'basic ', 'BASIC '] as $scheme) {
            $request = ServerRequest::fromString('', ['HTTP_X_AUTH' => $scheme . base64_encode('user:pass')]);

            $this->assertSame(['user', 'pass'], $request->credentials('X-Auth'));
        }
    }

    public function testFallsBackToTheStandardCredentialsWhenTheHeaderIsUnusable(): void
    {
        $variables = ['PHP_AUTH_USER' => 'user', 'PHP_AUTH_PW' => 'pass'];

        $this->assertSame(['user', 'pass'], ServerRequest::fromString('', $variables)->credentials('X-Auth'));
        $this->assertSame(
            ['user', 'pass'],
            ServerRequest::fromString('', ['HTTP_X_AUTH' => ''] + $variables)->credentials('X-Auth'),
        );
        $this->assertSame(
            ['user', 'pass'],
            ServerRequest::fromString('', ['HTTP_X_AUTH' => 'not base64 !'] + $variables)->credentials('X-Auth'),
        );
        $this->assertSame(
            ['user', 'pass'],
            ServerRequest::fromString('', ['HTTP_X_AUTH' => base64_encode('no-colon')] + $variables)
                ->credentials('X-Auth'),
        );
    }

    #[RunInSeparateProcess]
    public function testReadsTheCurrentRequestFromTheGlobals(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $request = ServerRequest::fromGlobals();

        $this->assertSame('203.0.113.7', $request->remoteAddress());
        $this->assertSame('', $request->body);
    }
}
