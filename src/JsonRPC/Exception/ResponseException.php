<?php

declare(strict_types=1);

namespace JsonRPC\Exception;

use Throwable;

/**
 * An error object returned by the server.
 *
 * @link https://www.jsonrpc.org/specification#error_object
 */
class ResponseException extends RpcCallFailedException
{
    /**
     * @param mixed $data Additional information attached to the error object
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly mixed $data = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getData(): mixed
    {
        return $this->data;
    }
}
