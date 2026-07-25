<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Server;

use JsonRPC\Exception\InvalidParamsException;
use JsonRPC\Server\ParameterBinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

#[CoversClass(ParameterBinder::class)]
final class ParameterBinderTest extends TestCase
{
    private ParameterBinder $binder;

    protected function setUp(): void
    {
        $this->binder = new ParameterBinder();
    }

    private function signature(callable $procedure): ReflectionFunction
    {
        return new ReflectionFunction($procedure(...));
    }

    public function testPassesPositionalParametersThrough(): void
    {
        $signature = $this->signature(fn(int $a, int $b): int => $a + $b);

        $this->assertSame([1, 2], $this->binder->bind($signature, [1, 2]));
    }

    public function testOrdersNamedParametersLikeTheSignature(): void
    {
        $signature = $this->signature(fn(int $a, int $b): int => $a - $b);

        $this->assertSame(['a' => 1, 'b' => 2], $this->binder->bind($signature, ['b' => 2, 'a' => 1]));
    }

    public function testFillsMissingNamedParametersWithTheirDefault(): void
    {
        $signature = $this->signature(fn(string $name, string $greeting = 'Hello'): string => $greeting . $name);

        $this->assertSame(['name' => 'Bob', 'greeting' => 'Hello'], $this->binder->bind($signature, ['name' => 'Bob']));
    }

    public function testAcceptsNoParametersWhenNoneAreRequired(): void
    {
        $signature = $this->signature(fn(int $a = 1): int => $a);

        $this->assertSame([], $this->binder->bind($signature, []));
    }

    public function testRejectsTooFewParameters(): void
    {
        $signature = $this->signature(fn(int $a, int $b): int => $a + $b);

        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessage('Wrong number of arguments');

        $this->binder->bind($signature, [1]);
    }

    public function testRejectsTooManyParameters(): void
    {
        $signature = $this->signature(fn(int $a): int => $a);

        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessage('Too many arguments');

        $this->binder->bind($signature, [1, 2]);
    }

    public function testRejectsAMissingNamedParameterWithoutDefault(): void
    {
        $signature = $this->signature(fn(int $a, int $b = 2): int => $a + $b);

        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessage('Missing argument: a');

        $this->binder->bind($signature, ['b' => 5]);
    }

    public function testRejectsParametersTheProcedureDoesNotDeclare(): void
    {
        $signature = $this->signature(fn(int $a = 1, int $b = 2): int => $a + $b);

        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessage('Undefined arguments: c');

        $this->binder->bind($signature, ['a' => 1, 'c' => 3]);
    }
}
