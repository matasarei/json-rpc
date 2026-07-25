<?php

declare(strict_types=1);

namespace JsonRPC\Server;

use JsonRPC\Exception\InvalidJsonFormatException;
use JsonRPC\Exception\InvalidJsonRpcFormatException;
use JsonRPC\MiddlewareHandler;
use JsonRPC\ProcedureHandler;
use Throwable;

/**
 * Handles one request payload, whether it came alone or inside a batch.
 */
final readonly class RequestHandler
{
    /**
     * @param list<class-string> $localExceptions Exceptions the server handles itself
     */
    public function __construct(
        private ProcedureHandler $procedureHandler,
        private MiddlewareHandler $middlewareHandler,
        private ErrorResponseFactory $errorResponseFactory,
        private array $localExceptions = [],
    ) {
    }

    /**
     * @return array<string, mixed>|null The response, or null when nothing has to be answered
     *
     * @throws Throwable Exceptions the server handles itself
     */
    public function handle(mixed $payload, ?string $username, ?string $password): ?array
    {
        // A request carrying "id": null still expects an answer; only a request
        // without an id member at all is a notification.
        $isNotification = !is_array($payload) || !array_key_exists('id', $payload);
        $id = $isNotification ? null : $payload['id'];

        try {
            $this->validate($payload);

            $result = $this->call($payload, $username, $password);

            return $isNotification ? null : $this->success($result, $id);
        } catch (Throwable $exception) {
            foreach ($this->localExceptions as $localException) {
                if ($exception instanceof $localException) {
                    throw $exception;
                }
            }

            // A notification gets no answer, except when the request was too
            // malformed to tell whether it was one.
            if ($isNotification && !$exception instanceof InvalidJsonRpcFormatException) {
                return null;
            }

            return $this->failure($exception, $id);
        }
    }

    /**
     * @param array{jsonrpc: string, method: string, params?: array<array-key, mixed>} $payload
     */
    private function call(array $payload, ?string $username, ?string $password): mixed
    {
        $this->middlewareHandler->execute($username, $password, $payload['method']);

        return $this->procedureHandler->executeProcedure($payload['method'], $payload['params'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private function success(mixed $result, mixed $id): array
    {
        return ['jsonrpc' => '2.0', 'result' => $result, 'id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(Throwable $exception, mixed $id): array
    {
        // A malformed request has no identifier that can be trusted.
        if ($exception instanceof InvalidJsonFormatException || $exception instanceof InvalidJsonRpcFormatException) {
            $id = null;
        }

        return [
            'jsonrpc' => '2.0',
            'error' => $this->errorResponseFactory->create($exception),
            'id' => $id,
        ];
    }

    /**
     * @phpstan-assert array{jsonrpc: string, method: string, params?: array<array-key, mixed>} $payload
     *
     * @throws InvalidJsonRpcFormatException
     */
    private function validate(mixed $payload): void
    {
        if (
            !is_array($payload)
            || ($payload['jsonrpc'] ?? null) !== '2.0'
            || !is_string($payload['method'] ?? null)
            || (isset($payload['params']) && !is_array($payload['params']))
            // The specification allows a String, a Number or NULL as identifier.
            || (array_key_exists('id', $payload) && !is_scalar($payload['id']) && $payload['id'] !== null)
            || is_bool($payload['id'] ?? null)
        ) {
            throw new InvalidJsonRpcFormatException('Invalid JSON RPC payload');
        }
    }
}
