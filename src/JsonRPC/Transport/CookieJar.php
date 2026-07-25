<?php

declare(strict_types=1);

namespace JsonRPC\Transport;

use InvalidArgumentException;

/**
 * Cookies collected from responses and sent back on subsequent requests.
 */
final class CookieJar
{
    /**
     * @var array<string, string>
     */
    public private(set) array $cookies = [];

    /**
     * @param array<string, string> $cookies
     *
     * @throws InvalidArgumentException When a cookie would break the request
     */
    public function __construct(array $cookies = [])
    {
        $this->cookies = $this->validated($cookies);
    }

    /**
     * @param array<string, string> $cookies
     *
     * @throws InvalidArgumentException When a cookie would break the request
     */
    public function merge(array $cookies): void
    {
        $this->cookies = array_merge($this->cookies, $this->validated($cookies));
    }

    /**
     * @param array<string, string> $cookies
     *
     * @throws InvalidArgumentException When a cookie would break the request
     */
    public function replace(array $cookies): void
    {
        $this->cookies = $this->validated($cookies);
    }

    /**
     * @param array<string, string> $cookies
     *
     * @return array<string, string>
     *
     * @throws InvalidArgumentException
     */
    private function validated(array $cookies): array
    {
        foreach ($cookies as $name => $value) {
            if (!$this->isSafe($name) || !$this->isSafe($value)) {
                throw new InvalidArgumentException(
                    sprintf('The cookie "%s" contains a character that is not allowed in a header', $name),
                );
            }
        }

        return $cookies;
    }

    /**
     * A separator or a control character would let a value carry cookies, or
     * headers, that were never meant to be sent.
     */
    private function isSafe(string $value): bool
    {
        return preg_match('~[\x00-\x1F\x7F;]~', $value) !== 1;
    }

    /**
     * Store the cookies of "Set-Cookie" header values.
     *
     * Only the first name=value pair of a value is the cookie itself, the rest
     * are attributes (Path, Expires, HttpOnly, ...).
     *
     * @param list<string> $setCookieValues
     */
    public function store(array $setCookieValues): void
    {
        foreach ($setCookieValues as $header) {
            $attributes = explode(';', $header);
            $pair = array_shift($attributes);
            $separator = strpos($pair, '=');

            if ($separator === false) {
                continue;
            }

            $name = trim(substr($pair, 0, $separator));

            if ($name === '') {
                continue;
            }

            $value = trim(substr($pair, $separator + 1), " \t\r\n");

            // The server does not get to put a control character in a header
            // this client will send back on every later request.
            if (!$this->isSafe($name) || !$this->isSafe($value)) {
                continue;
            }

            // An empty value, "Max-Age=0" or a past "Expires" is how a server
            // deletes a cookie; keeping it would send a stale session back on
            // the next request.
            if ($value === '' || $this->isExpired($attributes)) {
                unset($this->cookies[$name]);

                continue;
            }

            $this->cookies[$name] = $value;
        }
    }

    /**
     * @param list<string> $attributes
     */
    private function isExpired(array $attributes): bool
    {
        $expires = null;

        foreach ($attributes as $attribute) {
            [$name, $value] = array_pad(explode('=', trim($attribute), 2), 2, '');
            $name = trim($name);
            $value = trim($value);

            // Max-Age wins over Expires, and one that is not a number is
            // ignored, as the specification asks, instead of being read as an
            // expiry of its own.
            if (strcasecmp($name, 'Max-Age') === 0 && is_numeric($value)) {
                return (float) $value <= 0;
            }

            if (strcasecmp($name, 'Expires') === 0) {
                $expires = $value;
            }
        }

        if ($expires === null) {
            return false;
        }

        $expiresAt = strtotime($expires);

        return $expiresAt !== false && $expiresAt <= time();
    }

    public function isEmpty(): bool
    {
        return $this->cookies === [];
    }

    /**
     * Value of the "Cookie" request header, or null when there is nothing to send.
     */
    public function headerValue(): ?string
    {
        if ($this->isEmpty()) {
            return null;
        }

        $pairs = [];

        foreach ($this->cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return implode('; ', $pairs);
    }
}
