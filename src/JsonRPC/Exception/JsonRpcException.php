<?php

declare(strict_types=1);

namespace JsonRPC\Exception;

use Throwable;

/**
 * Implemented by every exception thrown by this library, so that consumers can
 * catch anything coming from JSON-RPC without listing individual classes.
 */
interface JsonRpcException extends Throwable
{
}
