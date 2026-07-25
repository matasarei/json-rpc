<?php

declare(strict_types=1);

namespace JsonRPC;

/**
 * Error codes defined by the JSON-RPC 2.0 specification.
 *
 * @link https://www.jsonrpc.org/specification#error_object
 */
enum ErrorCode: int
{
    case ParseError = -32700;
    case InvalidRequest = -32600;
    case MethodNotFound = -32601;
    case InvalidParams = -32602;
    case InternalError = -32603;

    /**
     * The message defined by the specification for this error code.
     */
    public function message(): string
    {
        return match ($this) {
            self::ParseError => 'Parse error',
            self::InvalidRequest => 'Invalid Request',
            self::MethodNotFound => 'Method not found',
            self::InvalidParams => 'Invalid params',
            self::InternalError => 'Internal error',
        };
    }
}
