<?php

declare(strict_types=1);

namespace JsonRPC\Exception;

use RuntimeException;

/**
 * Base class of every exception thrown by this library, except the two bridges
 * extending their SPL counterparts (MethodNotFoundException, InvalidParamsException).
 */
class RpcCallFailedException extends RuntimeException implements JsonRpcException
{
}
