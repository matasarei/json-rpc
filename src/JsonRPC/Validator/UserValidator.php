<?php

declare(strict_types=1);

namespace JsonRPC\Validator;

use JsonRPC\Exception\AuthenticationFailureException;

/**
 * Checks the credentials of a request against the configured users.
 */
final readonly class UserValidator
{
    /**
     * @param array<array-key, mixed> $users Passwords keyed by username
     *
     * @throws AuthenticationFailureException
     */
    public function validate(array $users, ?string $username, ?string $password): void
    {
        if ($users === []) {
            return;
        }

        // Only a stored string can ever match: an entry that is not a string
        // (false, an integer coming from a configuration file) must not become
        // comparable through casting.
        $hasEntry = $username !== null && isset($users[$username]) && is_string($users[$username]);
        $expected = $hasEntry ? $users[$username] : '';

        // Comparing digests of fixed length keeps the comparison time
        // independent from the username being known and from the password
        // length, which would otherwise leak both.
        $matches = $password !== null && hash_equals(hash('sha256', $expected), hash('sha256', $password));

        if (!$matches || !$hasEntry) {
            throw new AuthenticationFailureException('Access not allowed');
        }
    }
}
