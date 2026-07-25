<?php

declare(strict_types=1);

namespace JsonRPC\Exception;

/**
 * The request never completed at the transport level.
 */
class ConnectionFailureException extends RpcCallFailedException
{
}
