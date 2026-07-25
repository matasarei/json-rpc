<?php

declare(strict_types=1);

namespace JsonRPC\Exception;

/**
 * At least one call of a batch failed.
 *
 * Both arrays are keyed by the position of the call in the batch, so a failed
 * batch never hides the results that did succeed.
 */
class BatchFailedException extends RpcCallFailedException
{
    /**
     * @param array<int, mixed> $results
     * @param array<int, JsonRpcException> $errors
     */
    public function __construct(
        string $message,
        private readonly array $results = [],
        private readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Results of the calls that succeeded, keyed by call position.
     *
     * @return array<int, mixed>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Errors of the calls that failed, keyed by call position.
     *
     * @return array<int, JsonRpcException>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
