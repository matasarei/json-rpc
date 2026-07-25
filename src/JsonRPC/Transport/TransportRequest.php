<?php

declare(strict_types=1);

namespace JsonRPC\Transport;

use InvalidArgumentException;

final readonly class TransportRequest
{
    /**
     * @param array<string, string> $headers Header values keyed by header name
     *
     * @throws InvalidArgumentException When a header would break the request
     */
    public function __construct(
        public string $url,
        public string $body,
        public array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            // A line break in a name or a value would let the rest of it be
            // read as headers of its own once written to the wire.
            if (preg_match('~[\r\n]~', $name . $value) === 1) {
                throw new InvalidArgumentException(
                    sprintf('The header "%s" contains a line break', $name),
                );
            }
        }
    }

    /**
     * Headers as "Name: value" lines.
     *
     * @return list<string>
     */
    public function headerLines(): array
    {
        $lines = [];

        foreach ($this->headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }
}
