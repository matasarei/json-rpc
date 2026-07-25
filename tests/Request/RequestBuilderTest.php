<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Request;

use JsonRPC\Request\RequestBuilder;
use JsonRPC\Tests\Doubles\SequentialIdGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestBuilder::class)]
final class RequestBuilderTest extends TestCase
{
    private RequestBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new RequestBuilder(new SequentialIdGenerator());
    }

    public function testBuildsARequestWithAGeneratedId(): void
    {
        $this->assertSame(['jsonrpc' => '2.0', 'method' => 'sum', 'id' => 1], $this->builder->build('sum'));
        $this->assertSame(['jsonrpc' => '2.0', 'method' => 'sum', 'id' => 2], $this->builder->build('sum'));
    }

    public function testKeepsAnExplicitId(): void
    {
        $this->assertSame(
            ['jsonrpc' => '2.0', 'method' => 'sum', 'id' => 'abc'],
            $this->builder->build('sum', [], [], 'abc'),
        );
    }

    public function testKeepsAnExplicitIdOfZero(): void
    {
        $this->assertSame(0, $this->builder->build('sum', [], [], 0)['id']);
    }

    public function testAddsParametersOnlyWhenThereAreSome(): void
    {
        $this->assertArrayNotHasKey('params', $this->builder->build('sum'));
        $this->assertSame([1, 2], $this->builder->build('sum', [1, 2])['params']);
        $this->assertSame(['a' => 1], $this->builder->build('sum', ['a' => 1])['params']);
    }

    public function testAddsRequestAttributes(): void
    {
        $payload = $this->builder->build('sum', [], ['auth' => 'token']);

        $this->assertSame(['auth' => 'token', 'jsonrpc' => '2.0', 'method' => 'sum', 'id' => 1], $payload);
    }

    public function testRequestAttributesCannotOverrideTheProtocolMembers(): void
    {
        $payload = $this->builder->build('sum', [], ['jsonrpc' => '1.0', 'method' => 'other']);

        $this->assertSame('2.0', $payload['jsonrpc']);
        $this->assertSame('sum', $payload['method']);
    }

    public function testBuildsANotificationWithoutAnId(): void
    {
        $payload = $this->builder->buildNotification('sum', [1, 2], ['auth' => 'token']);

        $this->assertSame(['auth' => 'token', 'jsonrpc' => '2.0', 'method' => 'sum', 'params' => [1, 2]], $payload);
        $this->assertArrayNotHasKey('id', $payload);
    }

    public function testGeneratesRandomIdsByDefault(): void
    {
        $builder = new RequestBuilder();

        $this->assertNotSame($builder->build('sum')['id'], $builder->build('sum')['id']);
    }
}
