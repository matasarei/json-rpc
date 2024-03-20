<?php

use JsonRPC\Client;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../vendor/autoload.php';

class ClientTest extends TestCase
{
    private $httpClient;

    public function setUp()
    {
        $this->httpClient = $this
            ->getMockBuilder('\JsonRPC\HttpClient')
            ->setMethods(array('execute'))
            ->getMock();
    }

    public function testSendBatch()
    {
        $client = new Client('', false, $this->httpClient);
        $response = [
            [
                'jsonrpc' => '2.0',
                'result' => 'c',
                'id' => 1,
            ],
            [
                'jsonrpc' => '2.0',
                'result' => 'd',
                'id' => 2,
            ]
        ];

        $this->httpClient
            ->expects($this->once())
            ->method('execute')
            ->with($this->stringContains('[{"jsonrpc":"2.0","method":"methodA","id":'))
            ->will($this->returnValue($response));


        $result = $client->batch()
            ->execute('methodA', ['a' => 'b'])
            ->execute('methodB', ['a' => 'b'])
            ->send();

        $this->assertEquals(array('c', 'd'), $result);
    }

    public function testSendRequest()
    {
        $client = new Client('', false, $this->httpClient);

        $this->httpClient
            ->expects($this->once())
            ->method('execute')
            ->with($this->stringContains('{"jsonrpc":"2.0","method":"methodA","id":'))
            ->will($this->returnValue(array('jsonrpc' => '2.0', 'result' => 'foobar', 'id' => 1)));

        $result = $client->execute('methodA', ['a' => 'b']);
        $this->assertEquals($result, 'foobar');
    }

    public function testSendRequestWithError()
    {
        $client = new Client('', false, $this->httpClient);

        $this->httpClient
            ->expects($this->once())
            ->method('execute')
            ->with($this->stringContains('{"jsonrpc":"2.0","method":"methodA","id":'))
            ->will($this->returnValue([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32601,
                    'message' => 'Method not found',
                ],
            ]));

        $this->setExpectedException('BadFunctionCallException');
        $client->execute('methodA', ['a' => 'b']);
    }

    public function testSendRequestWithErrorAndReturnExceptionEnabled()
    {
        $client = new Client('', true, $this->httpClient);

        $this->httpClient
            ->expects($this->once())
            ->method('execute')
            ->with($this->stringContains('{"jsonrpc":"2.0","method":"methodA","id":'))
            ->will($this->returnValue([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32601,
                    'message' => 'Method not found',
                ],
            ]));

        $result = $client->execute('methodA', ['a' => 'b']);
        $this->assertInstanceOf('BadFunctionCallException', $result);
    }
}
