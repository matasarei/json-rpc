<?php

declare(strict_types=1);

namespace JsonRPC;

use Closure;
use InvalidArgumentException;
use JsonException;
use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\Exception\ConnectionFailureException;
use JsonRPC\Exception\ResponseException;
use JsonRPC\Exception\ServerErrorException;
use JsonRPC\Transport\CookieJar;
use JsonRPC\Transport\DefaultTransportFactory;
use JsonRPC\Transport\TransportFactoryInterface;
use JsonRPC\Transport\TransportInterface;
use JsonRPC\Transport\TransportOptions;
use JsonRPC\Transport\TransportRequest;
use JsonRPC\Transport\TransportResponse;
use LogicException;
use Psr\Log\LoggerInterface;

/**
 * The HTTP session of a client: headers, credentials, cookies and logging.
 *
 * The bytes go over the wire through a TransportInterface, which defaults to
 * cURL or stream wrappers and can be replaced by any PSR-18 client.
 */
final class HttpClient
{
    private const SENSITIVE_HEADERS = ['authorization', 'cookie', 'set-cookie', 'proxy-authorization'];

    /**
     * @var array<string, string>
     */
    private array $headers = [
        'User-Agent' => 'JSON-RPC PHP Client <https://github.com/matasarei/json-rpc>',
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Connection' => 'close',
    ];

    private ?string $username = null;

    private ?string $password = null;

    private ?LoggerInterface $logger = null;

    private ?Closure $beforeRequest = null;

    private TransportOptions $options;

    private readonly bool $hasCustomTransport;

    public function __construct(
        private string $url = '',
        private ?TransportInterface $transport = null,
        private readonly CookieJar $cookies = new CookieJar(),
        private readonly TransportFactoryInterface $transportFactory = new DefaultTransportFactory(),
    ) {
        $this->hasCustomTransport = $transport !== null;
        $this->options = new TransportOptions();
    }

    public function withUrl(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function withUsername(string $username): self
    {
        $this->username = $username;

        return $this;
    }

    public function withPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Seconds to wait for the connection to be established.
     *
     * @throws LogicException When a transport was injected
     */
    public function withTimeout(int $timeout): self
    {
        $this->options = $this->configurableOptions()->withConnectTimeout($timeout);

        return $this;
    }

    /**
     * Seconds allowed for the whole transfer, 0 for no limit.
     *
     * @throws LogicException When a transport was injected
     */
    public function withExecutionTimeout(int $timeout): self
    {
        $this->options = $this->configurableOptions()->withTransferTimeout($timeout);

        return $this;
    }

    /**
     * @throws LogicException When a transport was injected
     */
    public function withoutSslVerification(): self
    {
        $this->options = $this->configurableOptions()->withSslVerification(false);

        return $this;
    }

    /**
     * Certificate authority bundle used to verify the server certificate.
     *
     * @throws LogicException When a transport was injected
     */
    public function withCaFile(string $path): self
    {
        $this->options = $this->configurableOptions()->withCaFile($path);

        return $this;
    }

    /**
     * Client certificate sent to the server.
     *
     * @throws LogicException When a transport was injected
     */
    public function withLocalCert(string $path): self
    {
        $this->options = $this->configurableOptions()->withLocalCert($path);

        return $this;
    }

    /**
     * Transport specific options: raw cURL options or stream context overrides.
     *
     * @param array<int|string, mixed> $options
     *
     * @throws LogicException When a transport was injected
     */
    public function withTransportOptions(array $options): self
    {
        $this->options = $this->configurableOptions()->withExtraOptions($options);

        return $this;
    }

    /**
     * @param array<string, string> $headers Values keyed by header name
     */
    public function withHeaders(array $headers): self
    {
        $this->headers = $this->mergeHeaders($this->headers, $headers);

        return $this;
    }

    /**
     * @param array<string, string> $cookies
     *
     * @throws InvalidArgumentException When a cookie would break the request
     */
    public function withCookies(array $cookies, bool $replace = false): self
    {
        if ($replace) {
            $this->cookies->replace($cookies);
        } else {
            $this->cookies->merge($cookies);
        }

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getCookies(): array
    {
        return $this->cookies->cookies;
    }

    /**
     * Receive request and response debug messages.
     *
     * Credentials carried by headers are redacted before they are logged.
     */
    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Called with ($client, $payload, $headers) right before the request is sent.
     */
    public function withBeforeRequestCallback(Closure $closure): self
    {
        $this->beforeRequest = $closure;

        return $this;
    }

    /**
     * Send a payload and return the decoded response, or null when the server
     * answered with an empty body or something that is not JSON.
     *
     * @param array<string, string> $headers Additional headers for this request
     *
     * @throws AccessDeniedException
     * @throws ConnectionFailureException
     * @throws ResponseException
     * @throws ServerErrorException
     * @throws InvalidArgumentException When a header carries a line break or a null byte
     */
    public function execute(string $payload, array $headers = []): mixed
    {
        if ($this->beforeRequest instanceof Closure) {
            ($this->beforeRequest)($this, $payload, $headers);
        }

        $request = new TransportRequest($this->url, $payload, $this->buildHeaders($headers));

        $this->logger?->debug('Request', [
            'url' => $request->url,
            'payload' => $request->body,
            'headers' => $this->redactHeaders($request->headers),
        ]);

        $response = $this->transport()->send($request);

        $this->logger?->debug('Response', [
            'status' => $response->statusCode,
            'payload' => $response->body,
            'headers' => $this->redactHeaders($response->headers),
        ]);

        $this->cookies->store($response->headerValues('Set-Cookie'));

        $decoded = $this->decode($response->body);

        $this->handleStatusCode($response, $decoded);
        $this->rejectUnreadableBody($response, $decoded);

        return $decoded;
    }

    private function transport(): TransportInterface
    {
        return $this->transport ??= $this->transportFactory->create($this->options);
    }

    /**
     * Connection settings belong to the built-in transports; an injected one
     * carries its own configuration.
     */
    private function configurableOptions(): TransportOptions
    {
        if ($this->hasCustomTransport) {
            throw new LogicException(
                'Connection settings do not apply to an injected transport, configure that transport instead.',
            );
        }

        // The transport is built from these options on first use, so it has to
        // be built again once they change.
        $this->transport = null;

        return $this->options;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function buildHeaders(array $headers): array
    {
        $generated = [];

        if ($this->username !== null && $this->password !== null) {
            $generated['Authorization'] = 'Basic ' . base64_encode($this->username . ':' . $this->password);
        }

        $cookies = $this->cookies->headerValue();

        if ($cookies !== null) {
            $generated['Cookie'] = $cookies;
        }

        // What the caller asked for wins over what this class generates, and
        // replaces it rather than being sent next to it.
        return $this->mergeHeaders($generated, $this->mergeHeaders($this->headers, $headers));
    }

    /**
     * Header names are case insensitive, so a value given by the caller has to
     * replace a default that only differs in case instead of being sent next
     * to it.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function mergeHeaders(array $headers, array $overrides): array
    {
        foreach ($overrides as $name => $value) {
            foreach (array_keys($headers) as $existing) {
                if (strcasecmp($existing, $name) === 0) {
                    unset($headers[$existing]);
                }
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    private function decode(string $body): mixed
    {
        if (trim($body) === '') {
            return null;
        }

        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * @throws AccessDeniedException
     * @throws ConnectionFailureException
     * @throws ResponseException
     * @throws ServerErrorException
     */
    private function handleStatusCode(TransportResponse $response, mixed $decoded): void
    {
        $message = sprintf('Response with status code %d', $response->statusCode);
        $carriesError = $this->carriesError($decoded);

        $exception = match ($response->statusCode) {
            401, 403 => new AccessDeniedException($message),
            404 => new ConnectionFailureException($message),
            // A server that says what went wrong in a JSON-RPC error object has
            // answered the call; a 500 carrying anything else, an error page of
            // a gateway for instance, is a server failure.
            500 => $carriesError ? null : new ServerErrorException($message),
            default => null,
        };

        if ($exception !== null) {
            throw $exception;
        }

        // Redirects are never followed, so a 3xx is terminal whatever it carries.
        if ($response->statusCode >= 300 && $response->statusCode < 400) {
            throw new ResponseException(sprintf('Unexpected response with status code %d', $response->statusCode));
        }

        if ($carriesError || $response->statusCode < 400 || $response->statusCode >= 600) {
            return;
        }

        throw new ResponseException(sprintf('Unexpected response with status code %d', $response->statusCode));
    }

    /**
     * Whether the answer is a JSON-RPC error object, the one thing that makes
     * an error status code a real answer rather than a failure.
     */
    private function carriesError(mixed $decoded): bool
    {
        if (!is_array($decoded)) {
            return false;
        }

        if (!array_is_list($decoded)) {
            return isset($decoded['error']);
        }

        foreach ($decoded as $answer) {
            if (is_array($answer) && isset($answer['error'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * A body that could not be read while it announces a content encoding was
     * most likely never decoded, which is worth saying instead of reporting a
     * malformed payload.
     *
     * The header alone proves nothing: clients that decompress transparently,
     * Symfony's PSR-18 client and cURL with CURLOPT_ENCODING among them, leave
     * it on the response they hand over already decoded.
     *
     * @throws ResponseException
     */
    private function rejectUnreadableBody(TransportResponse $response, mixed $decoded): void
    {
        if ($decoded !== null || trim($response->body) === '') {
            return;
        }

        $encoding = $response->headerValues('Content-Encoding')[0] ?? null;

        if ($encoding === null || $encoding === '' || strcasecmp($encoding, 'identity') === 0) {
            return;
        }

        throw new ResponseException(
            sprintf('The response body could not be read, it is encoded with "%s"', $encoding),
        );
    }

    /**
     * @param array<string, string|list<string>> $headers
     *
     * @return array<string, string|list<string>>
     */
    private function redactHeaders(array $headers): array
    {
        $redacted = [];

        foreach ($headers as $name => $value) {
            $redacted[$name] = in_array(strtolower($name), self::SENSITIVE_HEADERS, true) ? '[redacted]' : $value;
        }

        return $redacted;
    }
}
