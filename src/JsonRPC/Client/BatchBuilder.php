<?php

declare(strict_types=1);

namespace JsonRPC\Client;

use JsonRPC\Exception\BatchFailedException;
use JsonRPC\Exception\JsonRpcException;
use JsonRPC\HttpClient;
use JsonRPC\Request\RequestBuilder;
use JsonRPC\Response\ResponseParser;
use LogicException;

/**
 * Collects the calls of a batch and sends them as a single request.
 *
 * A builder is single use: Client::batch() hands out a new one every time, so a
 * batch can never be sent twice by accident.
 */
final class BatchBuilder
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $payloads = [];

    /**
     * Ids of the calls that expect an answer, in call order.
     *
     * @var list<int|string>
     */
    private array $expectedIds = [];

    private bool $sent = false;

    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly RequestBuilder $requestBuilder,
        private readonly ResponseParser $responseParser,
        private readonly bool $namedArguments = true,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function __call(string $method, array $params): self
    {
        return $this->execute($method, $this->arguments($params));
    }

    /**
     * @param array<array-key, mixed> $params
     * @param array<string, mixed> $attributes
     */
    public function execute(
        string $procedure,
        array $params = [],
        array $attributes = [],
        int|string|null $requestId = null,
    ): self {
        $payload = $this->requestBuilder->build($procedure, $params, $attributes, $requestId);

        $this->payloads[] = $payload;
        /** @var int|string $id */
        $id = $payload['id'];
        $this->expectedIds[] = $id;

        return $this;
    }

    /**
     * @param array<array-key, mixed> $params
     * @param array<string, mixed> $attributes
     */
    public function notify(string $procedure, array $params = [], array $attributes = []): self
    {
        $this->payloads[] = $this->requestBuilder->buildNotification($procedure, $params, $attributes);

        return $this;
    }

    /**
     * Send every call collected so far.
     *
     * @param array<string, string> $headers Additional headers for this request
     *
     * @return array<int, mixed> Results of the calls that expect an answer, in call order
     *
     * @throws BatchFailedException When at least one call failed
     * @throws JsonRpcException
     * @throws LogicException When the batch was already sent
     */
    public function send(array $headers = []): array
    {
        if ($this->sent) {
            throw new LogicException('This batch was already sent, call Client::batch() to start a new one.');
        }

        $this->sent = true;

        if ($this->payloads === []) {
            return [];
        }

        $response = $this->httpClient->execute(RequestBuilder::encode($this->payloads), $headers);

        if ($this->expectedIds === []) {
            return [];
        }

        ['results' => $results, 'errors' => $errors] = $this->responseParser->parseBatch($response, $this->expectedIds);

        if ($errors !== []) {
            throw new BatchFailedException(
                sprintf('%d of %d calls failed', count($errors), count($this->expectedIds)),
                $results,
                $errors,
            );
        }

        return $results;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>
     */
    private function arguments(array $params): array
    {
        if ($this->namedArguments && count($params) === 1 && is_array($params[0])) {
            return $params[0];
        }

        return $params;
    }
}
