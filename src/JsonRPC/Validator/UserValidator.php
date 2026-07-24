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

        // Always run a constant-time comparison, even for unknown usernames,
        // to avoid leaking which usernames exist through response timing.
        $expected = isset($users[$username]) ? (string) $users[$username] : '';
        $isKnownUser = isset($users[$username]);

        if (! hash_equals($expected, (string) $password) || ! $isKnownUser) {
            throw new AuthenticationFailureException('Access not allowed');
        }
    }
}
