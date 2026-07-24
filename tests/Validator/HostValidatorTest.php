<?php

use JsonRPC\Validator\HostValidator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

class HostValidatorTest extends TestCase
{
    public function testWithEmptyHosts()
    {
        $this->assertNull(HostValidator::validate([], '127.0.0.1', '127.0.0.1'));
    }

    public function testWithValidHosts()
    {
        $this->assertNull(HostValidator::validate(['127.0.0.1'], '127.0.0.1', '127.0.0.1'));
    }

    public function testWithValidNetwork()
    {
        $this->assertNull(HostValidator::validate(['192.168.10.1/24'], '192.168.10.1'), 'test ip match');
        $this->assertNull(HostValidator::validate(['192.168.10.1/24'], '192.168.10.250'), 'test ip match');
        $this->expectException('\JsonRPC\Exception\AccessDeniedException');
        HostValidator::validate(['192.168.10.1/24'], '192.168.11.1');
    }

    public function testWithNotAuthorizedHosts()
    {
        $this->expectException('\JsonRPC\Exception\AccessDeniedException');
        HostValidator::validate(['192.168.1.1'], '127.0.0.1', '127.0.0.1');
    }

    public function testNetMatchFailsClosedForInvalidClientIp()
    {
        $this->assertFalse(HostValidator::netMatch('not-an-ip', '192.168.10.0', '24'));
        $this->assertFalse(HostValidator::netMatch('::1', '192.168.10.0', '24'));
    }

    public function testNetMatchFailsClosedForOutOfRangeMask()
    {
        $this->assertFalse(HostValidator::netMatch('192.168.10.1', '192.168.10.0', '33'));
        $this->assertFalse(HostValidator::netMatch('192.168.10.1', '192.168.10.0', '-1'));
        $this->assertFalse(HostValidator::netMatch('192.168.10.1', '192.168.10.0', 'abc'));
    }

    public function testNetMatchWithZeroMaskMatchesEverything()
    {
        $this->assertTrue(HostValidator::netMatch('8.8.8.8', '192.168.10.0', '0'));
    }

    public function testIpv6ClientIsRejectedByIpv4Cidr()
    {
        $this->expectException('\JsonRPC\Exception\AccessDeniedException');
        HostValidator::validate(['192.168.10.0/24'], '::1');
    }
}
