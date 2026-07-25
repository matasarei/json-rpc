<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Validator;

use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\Validator\HostValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HostValidator::class)]
final class HostValidatorTest extends TestCase
{
    private HostValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new HostValidator();
    }

    public function testAllowsEveryClientWhenNoHostIsConfigured(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validate([], '192.168.0.1');
        $this->validator->validate([], null);
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function allowedClients(): array
    {
        return [
            'exact IPv4' => [['192.168.0.1'], '192.168.0.1'],
            'exact IPv6' => [['::1'], '::1'],
            'surrounding spaces' => [[' 192.168.0.1 '], '192.168.0.1'],
            'second entry' => [['10.0.0.1', '192.168.0.1'], '192.168.0.1'],
            'IPv4 range' => [['192.168.0.0/24'], '192.168.0.42'],
            'IPv4 range on a byte boundary' => [['10.0.0.0/8'], '10.255.255.255'],
            'IPv4 range inside a byte' => [['192.168.0.0/28'], '192.168.0.15'],
            'IPv4 single address range' => [['192.168.0.1/32'], '192.168.0.1'],
            'IPv4 catch all' => [['0.0.0.0/0'], '203.0.113.9'],
            'IPv6 range' => [['2001:db8::/32'], '2001:db8:1234::1'],
            'IPv6 range inside a byte' => [['2001:db8::/33'], '2001:db8:7fff::1'],
        ];
    }

    /**
     * @param list<string> $hosts
     */
    #[DataProvider('allowedClients')]
    public function testAllowsConfiguredClients(array $hosts, string $remoteAddress): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validate($hosts, $remoteAddress);
    }

    /**
     * @return array<string, array{list<string>, string|null}>
     */
    public static function rejectedClients(): array
    {
        return [
            'other address' => [['192.168.0.1'], '192.168.0.2'],
            'unknown address' => [['192.168.0.1'], null],
            'outside the range' => [['192.168.0.0/24'], '192.168.1.1'],
            'outside a range inside a byte' => [['192.168.0.0/28'], '192.168.0.16'],
            'outside an IPv6 range' => [['2001:db8::/32'], '2001:db9::1'],
            'outside an IPv6 range inside a byte' => [['2001:db8::/33'], '2001:db8:8000::1'],
            'IPv6 client against an IPv4 range' => [['192.168.0.0/24'], '::1'],
            'IPv4 client against an IPv6 range' => [['2001:db8::/32'], '192.168.0.1'],
            'malformed network' => [['not-an-address/24'], '192.168.0.1'],
            'malformed client address' => [['192.168.0.0/24'], 'not-an-address'],
            'malformed prefix' => [['192.168.0.0/abc'], '192.168.0.1'],
            'empty prefix' => [['192.168.0.0/'], '192.168.0.1'],
            'prefix out of range' => [['192.168.0.0/33'], '192.168.0.1'],
        ];
    }

    /**
     * @param list<string> $hosts
     */
    #[DataProvider('rejectedClients')]
    public function testRejectsEverythingElse(array $hosts, ?string $remoteAddress): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Access denied');

        $this->validator->validate($hosts, $remoteAddress);
    }
}
