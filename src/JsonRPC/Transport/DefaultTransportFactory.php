<?php

declare(strict_types=1);

namespace JsonRPC\Transport;

/**
 * Picks the built-in transport that can run on this installation.
 */
final readonly class DefaultTransportFactory implements TransportFactoryInterface
{
    /**
     * @param bool|null $curlAvailable Overrides the cURL extension detection
     */
    public function __construct(private ?bool $curlAvailable = null)
    {
    }

    public function create(TransportOptions $options = new TransportOptions()): TransportInterface
    {
        $curlAvailable = $this->curlAvailable ?? extension_loaded('curl');

        return $curlAvailable ? new CurlTransport($options) : new StreamTransport($options);
    }
}
