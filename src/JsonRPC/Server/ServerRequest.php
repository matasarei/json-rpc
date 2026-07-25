<?php

declare(strict_types=1);

namespace JsonRPC\Server;

/**
 * The incoming HTTP request, as far as JSON-RPC is concerned.
 *
 * This is the only place where the request body and the server environment are
 * read, so a server can be driven from any framework by building the request
 * from that framework's own objects.
 */
final readonly class ServerRequest
{
    /**
     * @param array<string, mixed> $serverVariables Usually $_SERVER
     */
    private function __construct(
        public string $body,
        public array $serverVariables = [],
    ) {
    }

    /**
     * @param array<string, mixed> $serverVariables
     */
    public static function fromString(string $body, array $serverVariables = []): self
    {
        return new self($body, $serverVariables);
    }

    /**
     * Read the request from the PHP superglobals and the input stream.
     */
    public static function fromGlobals(): self
    {
        /** @var array<string, mixed> $serverVariables */
        $serverVariables = $_SERVER;

        return new self((string) file_get_contents('php://input'), $serverVariables);
    }

    public function serverVariable(string $name): ?string
    {
        $value = $this->serverVariables[$name] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    public function remoteAddress(): ?string
    {
        return $this->serverVariable('REMOTE_ADDR');
    }

    /**
     * Credentials of the request.
     *
     * They are read from the given header when the server was configured with
     * one, and from the PHP_AUTH_* variables otherwise.
     *
     * @param string|null $headerName Name of an alternative authentication header
     *
     * @return array{0: string|null, 1: string|null} Username and password
     */
    public function credentials(?string $headerName = null): array
    {
        $fromHeader = $headerName === null ? null : $this->credentialsFromHeader($headerName);

        return $fromHeader ?? [$this->serverVariable('PHP_AUTH_USER'), $this->serverVariable('PHP_AUTH_PW')];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function credentialsFromHeader(string $headerName): ?array
    {
        $value = $this->serverVariable('HTTP_' . str_replace('-', '_', strtoupper($headerName)));

        if ($value === null || $value === '') {
            return null;
        }

        // A proxy forwarding the original header passes "Basic <base64>" along;
        // a bare base64 value is accepted just as well.
        $value = trim($value);

        if (stripos($value, 'Basic ') === 0) {
            $value = trim(substr($value, 6));
        }

        $credentials = base64_decode($value, true);

        if ($credentials === false || !str_contains($credentials, ':')) {
            return null;
        }

        [$username, $password] = explode(':', $credentials, 2);

        return [$username, $password];
    }
}
