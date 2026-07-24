<?php

namespace JsonRPC\Validator;

use JsonRPC\Exception\AuthenticationFailureException;

/**
 * Class UserValidator
 *
 * @package JsonRPC\Validator
 * @author  Frederic Guillot
 */
class UserValidator
{
    public static function validate(array $users, $username, $password)
    {
        if (empty($users)) {
            return;
        }

        $isKnownUser = isset($users[$username]);
        $expected = $isKnownUser ? (string) $users[$username] : '';

        // Compare fixed-length digests so the comparison takes the same time
        // for unknown usernames and passwords of any length, to avoid leaking
        // which usernames exist through response timing. A missing password
        // (null) is always rejected, even against an empty stored password.
        $match = is_string($password) && hash_equals(
            hash('sha256', $expected),
            hash('sha256', $password)
        );

        if (! $match || ! $isKnownUser) {
            throw new AuthenticationFailureException('Access not allowed');
        }
    }
}
