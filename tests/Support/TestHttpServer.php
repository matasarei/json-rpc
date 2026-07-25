<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Support;

use RuntimeException;

/**
 * A PHP built-in web server the transport tests talk to for real.
 *
 * Booted once per test run and shut down when the process exits, so the
 * transports can be exercised over an actual socket instead of shadowed
 * global functions.
 */
final class TestHttpServer
{
    private static ?self $instance = null;

    /**
     * @param resource $process
     */
    private function __construct(private $process, private readonly string $baseUrl)
    {
    }

    public static function instance(): self
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        $port = self::freePort();
        $router = __DIR__ . '/../Fixture/router.php';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = proc_open(
            sprintf('exec php -S 127.0.0.1:%d %s', $port, escapeshellarg($router)),
            $descriptors,
            $pipes,
            null,
            // Without workers the built-in server is single threaded, so the
            // deliberately slow route would block every following request.
            array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '4']),
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the test HTTP server');
        }

        fclose($pipes[0]);

        $server = new self($process, sprintf('http://127.0.0.1:%d', $port));
        $server->waitUntilReady($port);

        self::$instance = $server;
        register_shutdown_function(static fn() => $server->stop());

        return $server;
    }

    public function url(string $path): string
    {
        return $this->baseUrl . $path;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    private function waitUntilReady(int $port): void
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

            if (is_resource($socket)) {
                fclose($socket);

                return;
            }

            usleep(50_000);
        }

        $this->stop();

        throw new RuntimeException('The test HTTP server did not start in time');
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            throw new RuntimeException('Unable to reserve a port for the test HTTP server');
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($name === false) {
            throw new RuntimeException('Unable to read the reserved port');
        }

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
