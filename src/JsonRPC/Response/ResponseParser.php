<?php

declare(strict_types=1);

namespace JsonRPC\Response;

use JsonRPC\ErrorCode;
use JsonRPC\Exception\InvalidJsonFormatException;
use JsonRPC\Exception\InvalidJsonRpcFormatException;
use JsonRPC\Exception\InvalidParamsException;
use JsonRPC\Exception\JsonRpcException;
use JsonRPC\Exception\MethodNotFoundException;
use JsonRPC\Exception\ResponseException;

/**
 * Turns a decoded server answer into a result, or into the matching exception.
 */
final readonly class ResponseParser
{
    /**
     * @throws JsonRpcException
     */
    public function parse(mixed $payload): mixed
    {
        if (!is_array($payload)) {
            throw new InvalidJsonFormatException('Malformed payload');
        }

        $error = $this->errorOf($payload);

        if ($error instanceof JsonRpcException) {
            throw $error;
        }

        return $payload['result'] ?? null;
    }

    /**
     * Match the answers of a batch to the calls that produced them.
     *
     * The specification allows the server to answer in any order, so responses
     * are correlated by id rather than by position.
     *
     * @param list<int|string> $expectedIds Ids of the calls, in call order
     *
     * @return array{results: array<int, mixed>, errors: array<int, JsonRpcException>}
     *
     * @throws InvalidJsonFormatException
     */
    public function parseBatch(mixed $payload, array $expectedIds): array
    {
        if (!is_array($payload) || !array_is_list($payload)) {
            throw new InvalidJsonFormatException('Malformed payload');
        }

        $answers = [];

        foreach ($payload as $answer) {
            if (is_array($answer) && isset($answer['id']) && (is_int($answer['id']) || is_string($answer['id']))) {
                $answers[(string) $answer['id']] = $answer;
            }
        }

        $results = [];
        $errors = [];

        foreach ($expectedIds as $position => $id) {
            $answer = $answers[(string) $id] ?? null;

            if ($answer === null) {
                $errors[$position] = new ResponseException(
                    sprintf('No response received for the request with id %s', (string) $id),
                );

                continue;
            }

            $error = $this->errorOf($answer);

            if ($error instanceof JsonRpcException) {
                $errors[$position] = $error;

                continue;
            }

            $results[$position] = $answer['result'] ?? null;
        }

        return ['results' => $results, 'errors' => $errors];
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function errorOf(array $payload): ?JsonRpcException
    {
        if (!isset($payload['error']['code'])) {
            return null;
        }

        /** @var array{code: mixed, message?: mixed, data?: mixed} $error */
        $error = $payload['error'];
        $code = (int) $error['code'];
        $message = (string) ($error['message'] ?? '');
        $data = $error['data'] ?? null;

        return match (ErrorCode::tryFrom($code)) {
            ErrorCode::ParseError => new InvalidJsonFormatException('Parse error: ' . $message, $code, null, $data),
            ErrorCode::InvalidRequest => new InvalidJsonRpcFormatException('Invalid Request: ' . $message, $code, null, $data),
            ErrorCode::MethodNotFound => new MethodNotFoundException('Procedure not found: ' . $message, $code),
            ErrorCode::InvalidParams => new InvalidParamsException('Invalid arguments: ' . $message, $code),
            default => new ResponseException($message, $code, null, $data),
        };
    }
}
