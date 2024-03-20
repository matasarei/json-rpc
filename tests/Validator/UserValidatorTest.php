<?php

use JsonRPC\Validator\UserValidator;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../vendor/autoload.php';

class UserValidatorTest extends TestCase
{
    public function testWithEmptyHosts()
    {
        $this->assertNull(UserValidator::validate(array(), 'user', 'pass'));
    }

    public function testWithValidHosts()
    {
        $this->assertNull(UserValidator::validate(array('user' => 'pass'), 'user', 'pass'));
    }

    public function testWithNotAuthorizedHosts()
    {
        $this->setExpectedException('\JsonRPC\Exception\AuthenticationFailureException');
        UserValidator::validate(array('user' => 'pass'), 'user', 'wrong password');
    }
}
