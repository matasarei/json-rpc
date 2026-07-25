<?php

declare(strict_types=1);

namespace JsonRPC\Exception;

/**
 * The server answered with a 5xx status code.
 */
class ServerErrorException extends RpcCallFailedException
{
}
