<?php

use JsonRPC\Validator\JsonEncodingValidator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

class JsonEncodingValidatorTest extends TestCase
{
    public function testWithValidJson()
    {
        json_encode('{"foo": "bar"}');
        $this->assertNull(JsonEncodingValidator::validate());
    }

    public function testWithJsonError()
    {
        json_encode("\xB1\x31");

        $this->expectException('\JsonRPC\Exception\ResponseEncodingFailureException');
        JsonEncodingValidator::validate();
    }
}
