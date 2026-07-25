<?php

declare(strict_types=1);

namespace JsonRPC\Request;

use JsonException;
use JsonRPC\Exception\RpcCallFailedException;

/**
 * Assembles request payloads.
 *
 * Payloads are returned as arrays: a single request is encoded by the client,
 * a batch is encoded once as a whole.
 */
final readonly class RequestBuilder
{
    public function __construct(private IdGeneratorInterface $idGenerator = new RandomIdGenerator())
    {
    }

    /**
     * Encode a payload, reporting what cannot be encoded as a library exception
     * rather than a bare JsonException.
     *
     * @param array<array-key, mixed> $payload
     *
     * @throws RpcCallFailedException
     */
    public static function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RpcCallFailedException(
                'The request cannot be encoded: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }
    }

    /**
     * @param array<array-key, mixed> $params
     * @param array<string, mixed> $attributes Extra members added to the payload
     * @param int|string|null $id Identifier of the request, generated when null
     *
     * @return array<string, mixed>
     */
    public function build(string $procedure, array $params = [], array $attributes = [], int|string|null $id = null): array
    {
        return $this->payload($procedure, $params, $attributes, $id ?? $this->idGenerator->generate());
    }

    /**
     * Build a notification: without an id member, the server must not answer.
     *
     * @param array<array-key, mixed> $params
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    public function buildNotification(string $procedure, array $params = [], array $attributes = []): array
    {
        return $this->payload($procedure, $params, $attributes, null);
    }

    /**
     * @param array<array-key, mixed> $params
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private function payload(string $procedure, array $params, array $attributes, int|string|null $id): array
    {
        $payload = array_merge($attributes, [
            'jsonrpc' => '2.0',
            'method' => $procedure,
        ]);

        if ($id !== null) {
            $payload['id'] = $id;
        }

        if ($params !== []) {
            $payload['params'] = $params;
        }

        return $payload;
    }
}
