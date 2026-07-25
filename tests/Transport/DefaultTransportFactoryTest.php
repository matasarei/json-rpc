<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Transport;

use JsonRPC\Transport\CurlTransport;
use JsonRPC\Transport\DefaultTransportFactory;
use JsonRPC\Transport\StreamTransport;
use JsonRPC\Transport\TransportOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultTransportFactory::class)]
final class DefaultTransportFactoryTest extends TestCase
{
    public function testUsesCurlWhenTheExtensionIsAvailable(): void
    {
        $this->assertInstanceOf(CurlTransport::class, (new DefaultTransportFactory(true))->create());
    }

    public function testFallsBackToStreamsWithoutTheCurlExtension(): void
    {
        $this->assertInstanceOf(StreamTransport::class, (new DefaultTransportFactory(false))->create());
    }

    public function testDetectsTheExtensionWhenNotToldOtherwise(): void
    {
        $expected = extension_loaded('curl') ? CurlTransport::class : StreamTransport::class;

        $this->assertInstanceOf($expected, (new DefaultTransportFactory())->create(new TransportOptions()));
    }
}
