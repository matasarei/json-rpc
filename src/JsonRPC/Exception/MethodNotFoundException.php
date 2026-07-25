<?php

declare(strict_types=1);

namespace JsonRPC\Exception;

use BadFunctionCallException;

/**
 * The procedure does not exist (error code -32601).
 *
 * Extends the SPL exception the server maps to -32601, so procedures throwing
 * BadFunctionCallException keep working and existing catch blocks still match.
 */
class MethodNotFoundException extends BadFunctionCallException implements JsonRpcException
{
}
