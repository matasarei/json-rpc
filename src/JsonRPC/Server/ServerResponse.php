<?php

declare(strict_types=1);

namespace JsonRPC\Server;

use Closure;

/**
 * What the server answers.
 *
 * The response is returned instead of being written out, so it can be handed to
 * a framework response object. send() is there for plain PHP scripts.
 */
final readonly class ServerResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $body,
        public int $statusCode = 200,
        public array $headers = ['Content-Type' => 'application/json'],
    ) {
    }

    /**
     * Write the response out.
     *
     * @param Closure(string): mixed|null $emitHeader Defaults to header()
     * @param Closure(int): mixed|null $emitStatus Defaults to http_response_code()
     */
    public function send(?Closure $emitHeader = null, ?Closure $emitStatus = null): void
    {
        $emitHeader ??= header(...);
        $emitStatus ??= http_response_code(...);

        $emitStatus($this->statusCode);

        foreach ($this->headers as $name => $value) {
            $emitHeader($name . ': ' . $value);
        }

        echo $this->body;
    }
}
