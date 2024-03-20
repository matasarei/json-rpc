<?php

namespace JsonRPC\Response;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../vendor/autoload.php';

function header($value)
{
    HeaderMockTest::$functions->header($value);
}

abstract class HeaderMockTest extends TestCase
{
    public static $functions;

    public function setUp()
    {
        self::$functions = $this
            ->getMockBuilder('stdClass')
            ->setMethods(array('header'))
            ->getMock();
    }
}
