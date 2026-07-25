<?php

declare(strict_types=1);

namespace JsonRPC\Tests;

use InvalidArgumentException;
use JsonRPC\Exception\InvalidParamsException;
use JsonRPC\Exception\MethodNotFoundException;
use JsonRPC\ProcedureHandler;
use JsonRPC\Tests\Doubles\Procedures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProcedureHandler::class)]
final class ProcedureHandlerTest extends TestCase
{
    private ProcedureHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new ProcedureHandler();
    }

    public function testExecutesARegisteredCallback(): void
    {
        $this->handler->withCallback('sum', fn(int $a, int $b): int => $a + $b);

        $this->assertSame(7, $this->handler->executeProcedure('sum', [3, 4]));
        $this->assertSame(7, $this->handler->executeProcedure('sum', ['b' => 4, 'a' => 3]));
    }

    public function testExecutesSeveralCallbacksRegisteredAtOnce(): void
    {
        $this->handler->withCallbackArray([
            'sum' => fn(int $a, int $b): int => $a + $b,
            'ping' => fn(): string => 'pong',
        ]);

        $this->assertSame(7, $this->handler->executeProcedure('sum', [3, 4]));
        $this->assertSame('pong', $this->handler->executeProcedure('ping'));
    }

    public function testExecutesAMethodOfAClassItInstantiates(): void
    {
        $this->handler->withClassAndMethod('sum', Procedures::class);

        $this->assertSame(7, $this->handler->executeProcedure('sum', [3, 4]));
    }

    public function testExecutesAMethodUnderADifferentProcedureName(): void
    {
        $this->handler->withClassAndMethod('addition', Procedures::class, 'sum');

        $this->assertSame(7, $this->handler->executeProcedure('addition', [3, 4]));
    }

    public function testExecutesAMethodOfAGivenInstance(): void
    {
        $this->handler->withClassAndMethod('marker', new Procedures('built by hand'));

        $this->assertSame('built by hand', $this->handler->executeProcedure('marker'));
    }

    public function testExecutesSeveralClassMethodsRegisteredAtOnce(): void
    {
        $this->handler->withClassAndMethodArray([
            'addition' => [Procedures::class, 'sum'],
            'greeting' => [new Procedures(), 'greet'],
            // Without a method, the procedure name is the method name.
            'sum' => [Procedures::class],
        ]);

        $this->assertSame(7, $this->handler->executeProcedure('addition', [3, 4]));
        $this->assertSame('Hello Bob', $this->handler->executeProcedure('greeting', ['name' => 'Bob']));
        $this->assertSame(3, $this->handler->executeProcedure('sum', [1, 2]));
    }

    public function testRegistersProceduresWhoseNameLooksLikeANumber(): void
    {
        $this->handler
            ->withCallbackArray(['123' => fn(): string => 'callback'])
            ->withClassAndMethodArray(['456' => [Procedures::class, 'sum']]);

        $this->assertSame('callback', $this->handler->executeProcedure('123'));
        $this->assertSame(7, $this->handler->executeProcedure('456', [3, 4]));
    }

    public function testBuildsInstancesThroughTheConfiguredFactory(): void
    {
        $this->handler
            ->withInstanceFactory(fn(string $class): object => new Procedures('built by the container'))
            ->withClassAndMethod('marker', Procedures::class);

        $this->assertSame('built by the container', $this->handler->executeProcedure('marker'));
    }

    public function testExposesTheListedMethodsOfAnObject(): void
    {
        $this->handler->withObject(new Procedures(), ['sum', 'greet']);

        $this->assertSame(7, $this->handler->executeProcedure('sum', [3, 4]));
        $this->assertSame('Hi Bob', $this->handler->executeProcedure('greet', ['name' => 'Bob', 'greeting' => 'Hi']));
    }

    public function testDoesNotExposeMethodsThatWereNotListed(): void
    {
        $this->handler->withObject(new Procedures(), ['sum']);

        $this->expectException(MethodNotFoundException::class);

        $this->handler->executeProcedure('secret');
    }

    public function testRefusesToExposeMagicMethods(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Magic method "__call" cannot be exposed as a procedure');

        $this->handler->withObject(new Procedures(), ['__call']);
    }

    public function testRefusesToExposeAMethodThatDoesNotExist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Method "missing" does not exist');

        $this->handler->withObject(new Procedures(), ['missing']);
    }

    public function testCallsTheBeforeMethodWithTheNameOfTheMethodAboutToRun(): void
    {
        $procedures = new Procedures();
        $this->handler
            ->withBeforeMethod('beforeProcedure')
            ->withObject($procedures, ['sum'])
            // The procedure is named differently from the method it runs.
            ->withClassAndMethod('addition', $procedures, 'greet');

        $this->handler->executeProcedure('sum', [3, 4]);
        $this->handler->executeProcedure('addition', ['name' => 'Bob']);

        $this->assertSame(['sum', 'greet'], $procedures->before);
    }

    public function testIgnoresTheBeforeMethodWhenTheObjectDoesNotHaveIt(): void
    {
        $this->handler
            ->withBeforeMethod('missingBeforeMethod')
            ->withObject(new Procedures(), ['sum']);

        $this->assertSame(7, $this->handler->executeProcedure('sum', [3, 4]));
    }

    public function testFailsWhenTheProcedureIsUnknown(): void
    {
        $this->expectException(MethodNotFoundException::class);
        $this->expectExceptionMessage('Unable to find the procedure');

        $this->handler->executeProcedure('missing');
    }

    public function testFailsWhenTheBoundMethodDoesNotExist(): void
    {
        $this->handler->withClassAndMethod('broken', Procedures::class, 'missing');

        $this->expectException(MethodNotFoundException::class);

        $this->handler->executeProcedure('broken');
    }

    public function testFailsWhenTheParametersDoNotMatchTheProcedure(): void
    {
        $this->handler->withCallback('sum', fn(int $a, int $b): int => $a + $b);

        $this->expectException(InvalidParamsException::class);

        $this->handler->executeProcedure('sum', [3]);
    }
}
