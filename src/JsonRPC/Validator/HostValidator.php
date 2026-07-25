<?php

declare(strict_types=1);

namespace JsonRPC\Validator;

use JsonRPC\Exception\AccessDeniedException;

/**
 * Restricts which clients may reach the server, by address or CIDR range.
 *
 * Anything that cannot be parsed is treated as "no match", so a malformed entry
 * never widens the allowed set.
 */
final readonly class HostValidator
{
    /**
     * @param list<string> $hosts Addresses and CIDR ranges, IPv4 or IPv6
     *
     * @throws AccessDeniedException
     */
    public function validate(array $hosts, ?string $remoteAddress): void
    {
        if ($hosts === []) {
            return;
        }

        if ($remoteAddress === null || !$this->isAllowed($hosts, $remoteAddress)) {
            throw new AccessDeniedException('Access denied');
        }
    }

    /**
     * @param list<string> $hosts
     */
    private function isAllowed(array $hosts, string $remoteAddress): bool
    {
        foreach ($hosts as $host) {
            if ($this->matches(trim($host), $remoteAddress)) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $host, string $remoteAddress): bool
    {
        if (!str_contains($host, '/')) {
            return $host === $remoteAddress;
        }

        [$network, $prefix] = explode('/', $host, 2);

        return $this->isInNetwork($remoteAddress, $network, $prefix);
    }

    private function isInNetwork(string $address, string $network, string $prefix): bool
    {
        $addressBytes = inet_pton($address);
        $networkBytes = inet_pton($network);

        if ($addressBytes === false || $networkBytes === false) {
            return false;
        }

        // An IPv4 client never belongs to an IPv6 range, and the other way round.
        if (strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        if (!ctype_digit($prefix)) {
            return false;
        }

        $bits = (int) $prefix;

        if ($bits > strlen($addressBytes) * 8) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);

        if (strncmp($addressBytes, $networkBytes, $wholeBytes) !== 0) {
            return false;
        }

        $remainingBits = $bits % 8;

        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($addressBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
    }
}
