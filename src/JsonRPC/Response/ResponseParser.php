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
     * @param int|string|null $expectedId Identifier of the call being answered
     *
     * @throws JsonRpcException
     */
    public function parse(mixed $payload, int|string|null $expectedId = null): mixed
    {
        if (!is_array($payload)) {
            throw new InvalidJsonFormatException('Malformed payload');
        }

        // A single call is answered with a single response object; a list means
        // the server answered something else entirely.
        if (array_is_list($payload)) {
            throw new InvalidJsonRpcFormatException('Expected a single response but got a batch');
        }

        $error = $this->errorOf($payload);

        if ($error instanceof JsonRpcException) {
            throw $error;
        }

        // Without a result and without an error this is not an answer at all,
        // but something else that happens to be JSON: a maintenance page, or
        // whatever sits in front of the endpoint.
        if (!array_key_exists('result', $payload)) {
            throw new InvalidJsonRpcFormatException('The response has neither a result nor an error member');
        }

        $this->checkAnswersTheCall($payload, $expectedId);

        return $payload['result'];
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @throws ResponseException
     */
    private function checkAnswersTheCall(array $payload, int|string|null $expectedId): void
    {
        $id = $payload['id'] ?? null;

        if ($expectedId === null || $id === null) {
            return;
        }

        if (!is_scalar($id) || is_bool($id) || $this->idKey($id) !== $this->idKey($expectedId)) {
            throw new ResponseException(
                sprintf('The response does not answer the request with id %s', (string) $expectedId),
            );
        }
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
     * @throws InvalidJsonFormatException When the answer is not a batch
     * @throws JsonRpcException When the server refused the batch as a whole
     */
    public function parseBatch(mixed $payload, array $expectedIds): array
    {
        if (!is_array($payload)) {
            throw new InvalidJsonFormatException('Malformed payload');
        }

        // A batch can also be refused as a whole, with a single error object.
        if (!array_is_list($payload)) {
            throw $this->errorOf($payload) ?? new InvalidJsonFormatException('Malformed payload');
        }

        $answers = [];

        foreach ($payload as $answer) {
            if (is_array($answer) && isset($answer['id']) && is_scalar($answer['id']) && !is_bool($answer['id'])) {
                $answers[$this->idKey($answer['id'])][] = $answer;
            }
        }

        $results = [];
        $errors = [];

        foreach ($expectedIds as $position => $id) {
            $answer = $this->takeAnswer($answers, $id);

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

            // Held to the same standard as a single answer: with neither member
            // this is not one, and a null result would hide that.
            if (!array_key_exists('result', $answer)) {
                $errors[$position] = new InvalidJsonRpcFormatException(
                    'The response has neither a result nor an error member',
                );

                continue;
            }

            $results[$position] = $answer['result'];
        }

        return ['results' => $results, 'errors' => $errors];
    }

    /**
     * Take the answer of a call out of the pool.
     *
     * Answers are looked up by the text of their id, so that a server echoing 1
     * as "1" is still understood, but an answer whose id has the same type as
     * the one that was sent wins, and every answer is handed out only once.
     *
     * @param array<string, list<array<array-key, mixed>>> $answers
     *
     * @return array<array-key, mixed>|null
     */
    private function takeAnswer(array &$answers, int|string $id): ?array
    {
        $key = $this->idKey($id);

        foreach ($answers[$key] ?? [] as $index => $answer) {
            if (($answer['id'] ?? null) === $id) {
                array_splice($answers[$key], $index, 1);

                return $answer;
            }
        }

        if (!isset($answers[$key])) {
            return null;
        }

        return array_shift($answers[$key]);
    }

    private function idKey(int|float|string $id): string
    {
        // A whole number is the same id whether it decoded as int or float.
        return is_float($id) && $id === floor($id) && is_finite($id) ? (string) (int) $id : (string) $id;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function errorOf(array $payload): ?JsonRpcException
    {
        $error = $payload['error'] ?? null;

        if (!is_array($error) || !isset($error['code'])) {
            return null;
        }

        $code = is_numeric($error['code']) ? (int) $error['code'] : 0;
        $message = is_scalar($error['message'] ?? null) ? (string) $error['message'] : '';
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
