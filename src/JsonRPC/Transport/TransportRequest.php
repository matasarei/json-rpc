<?php

declare(strict_types=1);

namespace JsonRPC\Transport;

final readonly class TransportRequest
{
    /**
     * @param array<string, string> $headers Header values keyed by header name
     */
    public function __construct(
        public string $url,
        public string $body,
        public array $headers = [],
    ) {
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
