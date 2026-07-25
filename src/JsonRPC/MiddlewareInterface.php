<?php

declare(strict_types=1);

namespace JsonRPC;

use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\Exception\AuthenticationFailureException;

/**
 * Runs before a procedure is executed and can reject the call.
 */
interface MiddlewareInterface
{
    /**
     * @throws AuthenticationFailureException To answer 401 Unauthorized
     * @throws AccessDeniedException To answer 403 Forbidden
     */
    public function execute(?string $username, ?string $password, string $procedureName): void;
}
