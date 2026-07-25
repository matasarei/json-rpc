<?php

declare(strict_types=1);

namespace JsonRPC\Exception;

use InvalidArgumentException;

/**
 * The parameters do not match the procedure signature (error code -32602).
 *
 * Extends the SPL exception the server maps to -32602, so procedures throwing
 * InvalidArgumentException keep working and existing catch blocks still match.
 */
class InvalidParamsException extends InvalidArgumentException implements JsonRpcException
{
}
