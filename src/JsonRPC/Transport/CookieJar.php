<?php

declare(strict_types=1);

namespace JsonRPC\Transport;

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
     */
    public function __construct(array $cookies = [])
    {
        $this->cookies = $cookies;
    }

    /**
     * @param array<string, string> $cookies
     */
    public function merge(array $cookies): void
    {
        $this->cookies = array_merge($this->cookies, $cookies);
    }

    /**
     * @param array<string, string> $cookies
     */
    public function replace(array $cookies): void
    {
        $this->cookies = $cookies;
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
        foreach ($setCookieValues as $value) {
            $pair = explode(';', $value)[0];
            $separator = strpos($pair, '=');

            if ($separator === false) {
                continue;
            }

            $name = trim(substr($pair, 0, $separator));

            if ($name === '') {
                continue;
            }

            $this->cookies[$name] = trim(substr($pair, $separator + 1), " \t\r\n");
        }
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
