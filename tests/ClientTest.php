<?php

use JsonRPC\Client;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../vendor/autoload.php';

class ClientTest extends TestCase
{
    private $httpClient;

    protected function setUp(): void
    {
        $this->httpClient = $this
            ->getMockBuilder('\JsonRPC\HttpClient')
            ->onlyMethods(['execute'])
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

        $this->assertEquals(['c', 'd'], $result);
    }

    public function testSendNotification()
    {
        $client = new Client('', false, $this->httpClient);

        $this->httpClient
            ->expects($this->once())
            ->method('execute')
            ->with('{"jsonrpc":"2.0","method":"methodA","params":{"a":"b"}}')
            ->will($this->returnValue(null));

        $this->assertNull($client->notify('methodA', ['a' => 'b']));
    }

    public function testSendBatchOfNotificationsOnly()
    {
        $client = new Client('', false, $this->httpClient);

        $this->httpClient
            ->expects($this->once())
            ->method('execute')
            ->with('[{"jsonrpc":"2.0","method":"methodA"}, {"jsonrpc":"2.0","method":"methodB"}]')
            ->will($this->returnValue(null));

        $result = $client->batch()
            ->notify('methodA')
            ->notify('methodB')
            ->send();

        $this->assertNull($result);
    }

    public function testSendBatchWithMixedCallsAndNotifications()
    {
        $client = new Client('', false, $this->httpClient);
        $response = [
            [
                'jsonrpc' => '2.0',
                'result' => 'c',
                'id' => 1,
            ],
        ];

        $this->httpClient
            ->expects($this->once())
            ->method('execute')
            ->with($this->stringContains('{"jsonrpc":"2.0","method":"methodB"}]'))
            ->will($this->returnValue($response));

        $result = $client->batch()
            ->execute('methodA', ['a' => 'b'])
            ->notify('methodB')
            ->send();

        $this->assertEquals(['c'], $result);
    }

    public function testSendRequest()
    {
        $client = new Client('', false, $this->httpClient);

        $this->httpClient
            ->expects($this->once())
            ->method('execute')
            ->with($this->stringContains('{"jsonrpc":"2.0","method":"methodA","id":'))
            ->will($this->returnValue(['jsonrpc' => '2.0', 'result' => 'foobar', 'id' => 1]));

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

        $this->expectException('BadFunctionCallException');
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
