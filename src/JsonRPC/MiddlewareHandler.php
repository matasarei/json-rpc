<?php

declare(strict_types=1);

namespace JsonRPC;

use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\Exception\AuthenticationFailureException;

/**
 * Runs the registered middleware for a call.
 *
 * The handler holds no request state: everything a middleware needs is passed
 * to execute(), so a single handler can serve every request of a batch.
 */
final class MiddlewareHandler
{
    /**
     * @var list<MiddlewareInterface>
     */
    private array $middleware = [];

    public function withMiddleware(MiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    /**
     * @throws AuthenticationFailureException
     * @throws AccessDeniedException
     */
    public function execute(?string $username, ?string $password, string $procedureName): void
    {
        foreach ($this->middleware as $middleware) {
            $middleware->execute($username, $password, $procedureName);
        }
    }
}
