<?php

declare(strict_types=1);

namespace JsonRPC;

use JsonRPC\Client\BatchBuilder;
use JsonRPC\Exception\JsonRpcException;
use JsonRPC\Request\IdGeneratorInterface;
use JsonRPC\Request\RandomIdGenerator;
use JsonRPC\Request\RequestBuilder;
use JsonRPC\Response\ResponseParser;

/**
 * Calls procedures on a JSON-RPC 2.0 server.
 *
 * Procedures can be called by name through the magic call syntax:
 *
 *     $client = new Client('https://example.com/rpc');
 *     $client->addition(3, 4);
 */
final class Client
{
    /**
     * When the only argument of a magic call is an array, it is assumed to
     * contain named arguments.
     */
    private bool $namedArguments = true;

    private readonly HttpClient $httpClient;

    private readonly RequestBuilder $requestBuilder;

    private readonly ResponseParser $responseParser;

    public function __construct(
        string $url = '',
        ?HttpClient $httpClient = null,
        IdGeneratorInterface $idGenerator = new RandomIdGenerator(),
    ) {
        $this->httpClient = $httpClient ?? new HttpClient($url);
        $this->requestBuilder = new RequestBuilder($idGenerator);
        $this->responseParser = new ResponseParser();
    }

    /**
     * Pass the arguments of magic calls as positional arguments.
     */
    public function withPositionalArguments(): self
    {
        $this->namedArguments = false;

        return $this;
    }

    public function getHttpClient(): HttpClient
    {
        return $this->httpClient;
    }

    public function authentication(string $username, string $password): self
    {
        $this->httpClient
            ->withUsername($username)
            ->withPassword($password);

        return $this;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @throws JsonRpcException
     */
    public function __call(string $method, array $params): mixed
    {
        if ($this->namedArguments && count($params) === 1 && is_array($params[0])) {
            $params = $params[0];
        }

        return $this->execute($method, $params);
    }

    /**
     * Call a procedure and return its result.
     *
     * @param array<array-key, mixed> $params
     * @param array<string, mixed> $attributes Extra members added to the payload
     * @param int|string|null $requestId Identifier of the request, generated when null
     * @param array<string, string> $headers Additional headers for this request
     *
     * @throws JsonRpcException
     */
    public function execute(
        string $procedure,
        array $params = [],
        array $attributes = [],
        int|string|null $requestId = null,
        array $headers = [],
    ): mixed {
        $payload = $this->requestBuilder->build($procedure, $params, $attributes, $requestId);
        /** @var int|string $id */
        $id = $payload['id'];

        return $this->responseParser->parse($this->send($payload, $headers), $id);
    }

    /**
     * Call a procedure without asking for a result.
     *
     * @param array<array-key, mixed> $params
     * @param array<string, mixed> $attributes
     * @param array<string, string> $headers
     *
     * @throws JsonRpcException
     */
    public function notify(
        string $procedure,
        array $params = [],
        array $attributes = [],
        array $headers = [],
    ): void {
        $this->send($this->requestBuilder->buildNotification($procedure, $params, $attributes), $headers);
    }

    /**
     * Start a batch of calls sent as a single request.
     */
    public function batch(): BatchBuilder
    {
        return new BatchBuilder(
            $this->httpClient,
            $this->requestBuilder,
            $this->responseParser,
            $this->namedArguments,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    private function send(array $payload, array $headers): mixed
    {
        return $this->httpClient->execute(RequestBuilder::encode($payload), $headers);
    }
}
