<?php

declare(strict_types=1);

namespace JsonRPC\Tests;

use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\Exception\AuthenticationFailureException;
use JsonRPC\MiddlewareHandler;
use JsonRPC\MiddlewareInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiddlewareHandler::class)]
final class MiddlewareHandlerTest extends TestCase
{
    public function testRunsEveryMiddlewareWithTheCallContext(): void
    {
        $calls = [];
        $record = new class ($calls) implements MiddlewareInterface {
            /**
             * @param array<int, array{string|null, string|null, string}> $calls
             */
            public function __construct(public array &$calls)
            {
            }

            public function execute(?string $username, ?string $password, string $procedureName): void
            {
                $this->calls[] = [$username, $password, $procedureName];
            }
        };

        (new MiddlewareHandler())
            ->withMiddleware($record)
            ->withMiddleware($record)
            ->execute('user', 'pass', 'sum');

        $this->assertSame([['user', 'pass', 'sum'], ['user', 'pass', 'sum']], $calls);
    }

    public function testPassesMissingCredentialsAsNull(): void
    {
        $seen = [];
        $record = new class ($seen) implements MiddlewareInterface {
            /**
             * @param array<int, array{string|null, string|null, string}> $seen
             */
            public function __construct(public array &$seen)
            {
            }

            public function execute(?string $username, ?string $password, string $procedureName): void
            {
                $this->seen[] = [$username, $password, $procedureName];
            }
        };

        (new MiddlewareHandler())->withMiddleware($record)->execute(null, null, 'sum');

        $this->assertSame([[null, null, 'sum']], $seen);
    }

    public function testAMiddlewareCanRejectTheCall(): void
    {
        $handler = (new MiddlewareHandler())->withMiddleware(new class implements MiddlewareInterface {
            public function execute(?string $username, ?string $password, string $procedureName): void
            {
                throw new AccessDeniedException('Not allowed');
            }
        });

        $this->expectException(AccessDeniedException::class);

        $handler->execute('user', 'pass', 'sum');
    }

    public function testTheFirstRejectionStopsTheChain(): void
    {
        $reached = false;
        $handler = (new MiddlewareHandler())
            ->withMiddleware(new class implements MiddlewareInterface {
                public function execute(?string $username, ?string $password, string $procedureName): void
                {
                    throw new AuthenticationFailureException('Wrong credentials');
                }
            })
            ->withMiddleware(new class ($reached) implements MiddlewareInterface {
                public function __construct(public bool &$reached)
                {
                }

                public function execute(?string $username, ?string $password, string $procedureName): void
                {
                    $this->reached = true;
                }
            });

        try {
            $handler->execute('user', 'wrong', 'sum');
            $this->fail('An exception should have been thrown');
        } catch (AuthenticationFailureException) {
            $this->assertFalse($reached);
        }
    }

    public function testDoesNothingWithoutMiddleware(): void
    {
        $this->expectNotToPerformAssertions();

        (new MiddlewareHandler())->execute('user', 'pass', 'sum');
    }
}
