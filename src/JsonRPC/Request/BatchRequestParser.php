<?php

namespace JsonRPC\Request;

use JsonRPC\Exception\InvalidJsonRpcFormatException;
use JsonRPC\Response\ResponseBuilder;

/**
 * Class BatchRequestParser
 *
 * @package JsonRPC\Request
 * @author  Frederic Guillot
 */
class BatchRequestParser extends RequestParser
{
    /**
     * Maximum number of requests allowed in a batch (0 = unlimited)
     *
     * @var int
     */
    protected $batchLimit = 0;

    /**
     * Set the maximum number of requests allowed in a single batch
     *
     * @param  int $limit 0 disables the limit
     *
     * @return $this
     */
    public function withBatchLimit($limit)
    {
        $this->batchLimit = $limit;
        return $this;
    }

    /**
     * Parse incoming request
     *
     * @return string
     *
     * @throws \Exception
     */
    public function parse()
    {
        if ($this->batchLimit > 0 && count($this->payload) > $this->batchLimit) {
            return ResponseBuilder::create()
                ->withInternalErrorMasking($this->maskInternalErrors)
                ->withId(null)
                ->withException(new InvalidJsonRpcFormatException('Batch size limit exceeded'))
                ->build();
        }

        $responses = [];

        foreach ($this->payload as $payload) {
            $responses[] = RequestParser::create()
                ->withInternalErrorMasking($this->maskInternalErrors)
                ->withPayload($payload)
                ->withProcedureHandler($this->procedureHandler)
                ->withMiddlewareHandler($this->middlewareHandler)
                ->withCredentials($this->username, $this->password)
                ->withLocalException($this->localExceptions)
                ->parse();
        }

        $responses = array_filter($responses);
        return empty($responses) ? '' : '[' . implode(',', $responses) . ']';
    }

    /**
     * Return true if we have a batch request
     *
     * ex : [
     *   0 => '...',
     *   1 => '...',
     *   2 => '...',
     *   3 => '...',
     * ]
     *
     * @param  array $payload
     *
     * @return bool
     */
    public static function isBatchRequest(array $payload)
    {
        return array_keys($payload) === range(0, count($payload) - 1);
    }
}
