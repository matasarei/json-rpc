<?php

declare(strict_types=1);

namespace JsonRPC\Exception;

/**
 * The payload is valid JSON but not a valid JSON-RPC 2.0 request (error code -32600).
 */
class InvalidJsonRpcFormatException extends ResponseException
{
}
