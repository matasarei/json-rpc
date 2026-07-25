<?php

declare(strict_types=1);

namespace JsonRPC\Exception;

/**
 * The client is not allowed to reach the server (host restriction, middleware).
 */
class AccessDeniedException extends RpcCallFailedException
{
}
