<?php

declare(strict_types=1);

namespace JsonRPC\Transport;

final readonly class TransportResponse
{
    /**
     * @param array<string, list<string>> $headers Values keyed by lower-case header name
     */
    public function __construct(
        public int $statusCode,
        public string $body,
        public array $headers = [],
    ) {
    }

    /**
     * Build a response from raw header lines.
     *
     * Everything before the last status line is discarded, which drops the
     * headers of "100 Continue" and of any intermediate response.
     *
     * @param list<string> $lines Header lines, status lines included
     * @param int|null $statusCode Status code when the transport already knows it
     */
    public static function fromRawHeaders(string $body, array $lines, ?int $statusCode = null): self
    {
        $headers = [];
        $parsedStatusCode = null;

        foreach ($lines as $line) {
            if (preg_match('~^HTTP/\d+(?:\.\d+)?\s+(\d{3})~', $line, $matches) === 1) {
                $parsedStatusCode = (int) $matches[1];
                $headers = [];

                continue;
            }

            $separator = strpos($line, ':');

            if ($separator === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $separator)));
            $headers[$name][] = trim(substr($line, $separator + 1));
        }

        return new self($statusCode ?? $parsedStatusCode ?? 0, $body, $headers);
    }

    /**
     * All values of a header, in the order they were received.
     *
     * @return list<string>
     */
    public function headerValues(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }
}
