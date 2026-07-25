<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Doubles;

use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Keeps every record a subject logs, so tests can assert on them.
 */
final class SpyLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return array<string, mixed>
     */
    public function contextOf(string $message): array
    {
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                return $record['context'];
            }
        }

        throw new RuntimeException(sprintf('Nothing was logged with the message "%s"', $message));
    }
}
