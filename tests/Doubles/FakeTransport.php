<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Doubles;

use JsonRPC\Exception\ConnectionFailureException;
use JsonRPC\Transport\TransportInterface;
use JsonRPC\Transport\TransportRequest;
use JsonRPC\Transport\TransportResponse;
use RuntimeException;

/**
 * Replays canned responses and records what it was asked to send.
 */
final class FakeTransport implements TransportInterface
{
    /**
     * @var list<TransportRequest>
     */
    public array $requests = [];

    /**
     * @var list<TransportResponse>
     */
    private array $responses;

    private ?ConnectionFailureException $failure = null;

    public function __construct(TransportResponse ...$responses)
    {
        $this->responses = array_values($responses);
    }

    /**
     * @param array<string, list<string>> $headers
     */
    public static function withJson(mixed $payload, int $statusCode = 200, array $headers = []): self
    {
        return new self(new TransportResponse(
            $statusCode,
            (string) json_encode($payload, JSON_THROW_ON_ERROR),
            $headers,
        ));
    }

    public static function withBody(string $body, int $statusCode = 200): self
    {
        return new self(new TransportResponse($statusCode, $body));
    }

    public static function failing(string $message = 'Unable to establish a connection'): self
    {
        $transport = new self();
        $transport->failure = new ConnectionFailureException($message);

        return $transport;
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $this->requests[] = $request;

        if ($this->failure instanceof ConnectionFailureException) {
            throw $this->failure;
        }

        $response = array_shift($this->responses);

        if (!$response instanceof TransportResponse) {
            throw new RuntimeException('No response left in the fake transport');
        }

        return $response;
    }

    public function lastRequest(): TransportRequest
    {
        $request = end($this->requests);

        if (!$request instanceof TransportRequest) {
            throw new RuntimeException('The fake transport was never asked to send anything');
        }

        return $request;
    }
}
