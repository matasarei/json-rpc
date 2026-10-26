<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Server;

use JsonRPC\Exception\InvalidParamsException;
use JsonRPC\Server\ParameterBinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testAcceptsAsManyArgumentsAsAVariadicProcedureWants(): void
    {
        $signature = $this->signature(fn(int ...$numbers): array => $numbers);

        $this->assertSame([1, 2, 3], $this->binder->bind($signature, [1, 2, 3]));
    }

    public function testBindsNamedParametersOfAVariadicProcedure(): void
    {
        $signature = $this->signature(fn(string $name, string ...$rest): array => [$name, $rest]);

        $this->assertSame(['name' => 'x'], $this->binder->bind($signature, ['name' => 'x']));
    }

    public function testAcceptsValuesThatFitTheDeclaredTypes(): void
    {
        $signature = $this->signature(
            fn(int $a, float $b, string $c, bool $d, ?int $e, mixed $f, array $g): bool => true,
        );

        $arguments = [1, 2.5, 'three', true, null, 'anything', []];

        $this->assertSame($arguments, $this->binder->bind($signature, $arguments));
    }

    public function testWidensAnIntegerToAFloatLikePhpDoes(): void
    {
        $signature = $this->signature(fn(float $a): float => $a);

        $this->assertSame([1], $this->binder->bind($signature, [1]));
    }

    /**
     * @return array<string, array{list<mixed>}>
     */
    public static function valuesOfTheWrongType(): array
    {
        return [
            'string for int' => [['a', 1.0, 'c', true]],
            'null for int' => [[null, 1.0, 'c', true]],
            'string for float' => [[1, 'b', 'c', true]],
            'int for string' => [[1, 1.0, 3, true]],
            'int for bool' => [[1, 1.0, 'c', 1]],
        ];
    }

    /**
     * @param list<mixed> $params
     */
    #[DataProvider('valuesOfTheWrongType')]
    public function testRejectsValuesThatDoNotFitADeclaredScalarType(array $params): void
    {
        $signature = $this->signature(fn(int $a, float $b, string $c, bool $d): bool => true);

        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessage('Invalid type for argument');

        $this->binder->bind($signature, $params);
    }

    public function testChecksTypesOfNamedParametersToo(): void
    {
        $signature = $this->signature(fn(int $a, int $b = 2): int => $a + $b);

        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessage('Invalid type for argument: a, int expected');

        $this->binder->bind($signature, ['a' => 'not an int']);
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
