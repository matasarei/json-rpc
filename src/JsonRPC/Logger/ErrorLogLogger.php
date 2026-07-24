<?php

namespace JsonRPC\Logger;

use Psr\Log\AbstractLogger;

/**
 * Class ErrorLogLogger
 *
 * Minimal PSR-3 logger writing to the PHP error log,
 * used as the backend for the deprecated debug mode.
 *
 * @package JsonRPC\Logger
 */
class ErrorLogLogger extends AbstractLogger
{
    /**
     * Log a message to the PHP error log
     *
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array $context
     *
     * @return void
     */
    public function log($level, $message, array $context = []): void
    {
        error_log(sprintf(
            '==> %s: %s%s',
            $message,
            PHP_EOL,
            json_encode($context, JSON_PRETTY_PRINT)
        ));
    }
}
