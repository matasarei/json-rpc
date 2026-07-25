<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Doubles;

use JsonRPC\Transport\TransportFactoryInterface;
use JsonRPC\Transport\TransportInterface;
use JsonRPC\Transport\TransportOptions;
use RuntimeException;

/**
 * Hands out a prepared transport and keeps the options it was built with.
 */
final class RecordingTransportFactory implements TransportFactoryInterface
{
    public ?TransportOptions $options = null;

    public int $calls = 0;

    public function __construct(private readonly TransportInterface $transport)
    {
    }

    public function create(TransportOptions $options): TransportInterface
    {
        $this->options = $options;
        $this->calls++;

        return $this->transport;
    }

    public function usedOptions(): TransportOptions
    {
        if (!$this->options instanceof TransportOptions) {
            throw new RuntimeException('The factory was never asked for a transport');
        }

        return $this->options;
    }
}
