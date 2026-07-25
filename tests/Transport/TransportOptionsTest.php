<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Transport;

use JsonRPC\Transport\TransportOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransportOptions::class)]
final class TransportOptionsTest extends TestCase
{
    public function testDefaultsToAFiveSecondConnectTimeoutAndNoTransferLimit(): void
    {
        $options = new TransportOptions();

        $this->assertSame(5, $options->connectTimeout);
        $this->assertSame(0, $options->transferTimeout);
        $this->assertTrue($options->verifySsl);
        $this->assertNull($options->caFile);
        $this->assertNull($options->localCert);
        $this->assertSame([], $options->extraOptions);
    }

    public function testEveryWitherReturnsACopyAndKeepsTheOtherSettings(): void
    {
        $options = new TransportOptions();

        $this->assertSame(10, $options->withConnectTimeout(10)->connectTimeout);
        $this->assertSame(30, $options->withTransferTimeout(30)->transferTimeout);
        $this->assertFalse($options->withSslVerification(false)->verifySsl);
        $this->assertSame('/ca.pem', $options->withCaFile('/ca.pem')->caFile);
        $this->assertSame('/client.pem', $options->withLocalCert('/client.pem')->localCert);
        $this->assertSame([1 => 'a'], $options->withExtraOptions([1 => 'a'])->extraOptions);

        // The original is untouched by any of them.
        $this->assertSame(5, $options->connectTimeout);
        $this->assertSame(0, $options->transferTimeout);
        $this->assertTrue($options->verifySsl);
        $this->assertNull($options->caFile);
        $this->assertNull($options->localCert);
        $this->assertSame([], $options->extraOptions);
    }

    public function testWithersCompose(): void
    {
        $options = (new TransportOptions())
            ->withConnectTimeout(2)
            ->withTransferTimeout(20)
            ->withSslVerification(false)
            ->withCaFile('/ca.pem')
            ->withLocalCert('/client.pem')
            ->withExtraOptions(['a' => 1]);

        $this->assertSame(2, $options->connectTimeout);
        $this->assertSame(20, $options->transferTimeout);
        $this->assertFalse($options->verifySsl);
        $this->assertSame('/ca.pem', $options->caFile);
        $this->assertSame('/client.pem', $options->localCert);
        $this->assertSame(['a' => 1], $options->extraOptions);
    }

    public function testExtraOptionsAreMergedNotReplaced(): void
    {
        $options = (new TransportOptions())
            ->withExtraOptions(['a' => 1, 'b' => 2])
            ->withExtraOptions(['b' => 3, 'c' => 4]);

        $this->assertSame(['a' => 1, 'b' => 3, 'c' => 4], $options->extraOptions);
    }
}
