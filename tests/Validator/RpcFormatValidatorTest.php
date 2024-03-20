<?php

use JsonRPC\Validator\RpcFormatValidator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

class RpcFormatValidatorTest extends TestCase
{
    public function testWithMinimumRequirement()
    {
        $this->assertNull(RpcFormatValidator::validate(['jsonrpc' => '2.0', 'method' => 'foobar']));
    }

    public function testWithNoVersion()
    {
        $this->expectException('\JsonRPC\Exception\InvalidJsonRpcFormatException');
        RpcFormatValidator::validate(['method' => 'foobar']);
    }

    public function testWithNoMethod()
    {
        $this->expectException('\JsonRPC\Exception\InvalidJsonRpcFormatException');
        RpcFormatValidator::validate(['jsonrpc' => '2.0']);
    }

    public function testWithMethodNotString()
    {
        $this->expectException('\JsonRPC\Exception\InvalidJsonRpcFormatException');
        RpcFormatValidator::validate(['jsonrpc' => '2.0', 'method' => []]);
    }

    public function testWithBadVersion()
    {
        $this->expectException('\JsonRPC\Exception\InvalidJsonRpcFormatException');
        RpcFormatValidator::validate(['jsonrpc' => '1.0', 'method' => 'abc']);
    }

    public function testWithBadParams()
    {
        $this->expectException('\JsonRPC\Exception\InvalidJsonRpcFormatException');
        RpcFormatValidator::validate(['jsonrpc' => '2.0', 'method' => 'abc', 'params' => 'foobar']);
    }

    public function testWithParams()
    {
        $this->assertNull(RpcFormatValidator::validate(['jsonrpc' => '2.0', 'method' => 'abc', 'params' => [1, 2]]));
    }
}
