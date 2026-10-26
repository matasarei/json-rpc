<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Doubles;

/**
 * A class exposing procedures, used by the dispatch tests.
 */
class Procedures
{
    /**
     * @var list<string>
     */
    public array $before = [];

    public function __construct(public readonly string $marker = 'built by php')
    {
    }

    public function beforeProcedure(string $procedure): void
    {
        $this->before[] = $procedure;
    }

    public function sum(int $a, int $b): int
    {
        return $a + $b;
    }

    public function greet(string $name, string $greeting = 'Hello'): string
    {
        return sprintf('%s %s', $greeting, $name);
    }

    public function marker(): string
    {
        return $this->marker;
    }

    public function secret(): string
    {
        return 'not exposed';
    }

    public function __call(string $name, mixed $arguments): string
    {
        return 'magic';
    }
}
