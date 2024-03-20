<?php

use JsonRPC\Validator\JsonFormatValidator;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../vendor/autoload.php';

class JsonFormatValidatorTest extends TestCase
{
    public function testJsonParsedCorrectly()
    {
        $this->assertNull(JsonFormatValidator::validate(['foobar']));
    }

    public function testJsonNotParsedCorrectly()
    {
        $this->setExpectedException('\JsonRPC\Exception\InvalidJsonFormatException');
        JsonFormatValidator::validate('');
    }
}
