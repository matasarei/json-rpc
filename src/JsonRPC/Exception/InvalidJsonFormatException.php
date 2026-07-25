<?php

declare(strict_types=1);

namespace JsonRPC\Exception;

/**
 * The payload is not valid JSON (error code -32700).
 */
class InvalidJsonFormatException extends ResponseException
{
}
