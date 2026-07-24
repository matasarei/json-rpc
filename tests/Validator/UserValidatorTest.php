<?php

use JsonRPC\Validator\UserValidator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

class UserValidatorTest extends TestCase
{
    public function testWithEmptyHosts()
    {
        $this->assertNull(UserValidator::validate([], 'user', 'pass'));
    }

    public function testWithValidHosts()
    {
        $this->assertNull(UserValidator::validate(['user' => 'pass'], 'user', 'pass'));
    }

    public function testWithNotAuthorizedHosts()
    {
        $this->expectException('\JsonRPC\Exception\AuthenticationFailureException');
        UserValidator::validate(['user' => 'pass'], 'user', 'wrong password');
    }

    public function testMissingPasswordIsRejectedEvenWithEmptyStoredPassword()
    {
        $this->expectException('\JsonRPC\Exception\AuthenticationFailureException');
        UserValidator::validate(['user' => ''], 'user', null);
    }

    public function testEmptyPasswordMatchesEmptyStoredPassword()
    {
        $this->assertNull(UserValidator::validate(['user' => ''], 'user', ''));
    }

    public function testUnknownUserIsRejected()
    {
        $this->expectException('\JsonRPC\Exception\AuthenticationFailureException');
        UserValidator::validate(['user' => 'pass'], 'nobody', 'pass');
    }

    public function testNonStringStoredPasswordIsRejected()
    {
        $this->expectException('\JsonRPC\Exception\AuthenticationFailureException');
        UserValidator::validate(['user' => false], 'user', '');
    }
}
