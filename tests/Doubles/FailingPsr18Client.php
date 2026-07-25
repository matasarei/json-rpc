<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Doubles;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A bare PSR-18 client (no PSR-17 factories) that always fails to connect.
 */
final class FailingPsr18Client implements ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new class ('Name or service not known') extends RuntimeException implements ClientExceptionInterface {
        };
    }
}
