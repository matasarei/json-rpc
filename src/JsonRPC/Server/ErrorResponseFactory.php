<?php

declare(strict_types=1);

namespace JsonRPC\Server;

use BadFunctionCallException;
use InvalidArgumentException;
use JsonRPC\ErrorCode;
use JsonRPC\Exception\InvalidJsonFormatException;
use JsonRPC\Exception\InvalidJsonRpcFormatException;
use JsonRPC\Exception\ResponseEncodingFailureException;
use JsonRPC\Exception\ResponseException;
use Throwable;

/**
 * Turns whatever a procedure threw into the error member of a response.
 */
final readonly class ErrorResponseFactory
{
    /**
     * @param bool $maskInternalErrors Replace unrecognized exceptions by a generic internal error
     */
    public function __construct(private bool $maskInternalErrors = true)
    {
    }

    /**
     * @return array{code: int, message: string, data?: mixed}
     */
    public function create(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof InvalidJsonFormatException => $this->error(ErrorCode::ParseError),
            $exception instanceof InvalidJsonRpcFormatException => $this->error(ErrorCode::InvalidRequest),
            $exception instanceof BadFunctionCallException => $this->error(ErrorCode::MethodNotFound),
            // The message of an InvalidArgumentException describes what was
            // wrong with the parameters, but it can come from application code,
            // so it is only exposed when masking is off.
            $exception instanceof InvalidArgumentException => $this->error(
                ErrorCode::InvalidParams,
                $exception->getMessage(),
            ),
            $exception instanceof ResponseEncodingFailureException => $this->error(
                ErrorCode::InternalError,
                $exception->getMessage(),
            ),
            $exception instanceof ResponseException => $this->responseError($exception),
            $this->maskInternalErrors => $this->error(ErrorCode::InternalError),
            default => ['code' => $exception->getCode(), 'message' => $exception->getMessage()],
        };
    }

    /**
     * @return array{code: int, message: string, data?: mixed}
     */
    private function error(ErrorCode $code, mixed $data = null): array
    {
        $error = ['code' => $code->value, 'message' => $code->message()];

        if ($data !== null && $data !== '' && !$this->maskInternalErrors) {
            $error['data'] = $data;
        }

        return $error;
    }

    /**
     * @return array{code: int, message: string, data?: mixed}
     */
    private function responseError(ResponseException $exception): array
    {
        $error = ['code' => $exception->getCode(), 'message' => $exception->getMessage()];
        $data = $exception->getData();

        if ($data !== null && $data !== '') {
            $error['data'] = $data;
        }

        return $error;
    }
}
