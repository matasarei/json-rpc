<?php

declare(strict_types=1);

namespace JsonRPC\Exception;

/**
 * The response could not be encoded to JSON.
 */
class ResponseEncodingFailureException extends RpcCallFailedException
{
}
