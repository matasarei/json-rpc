<?php

/**
 * Router for the PHP built-in web server used by the transport tests.
 *
 * Started by \JsonRPC\Tests\Support\TestHttpServer, never autoloaded.
 */

declare(strict_types=1);

$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string) file_get_contents('php://input');

if (preg_match('~^/status/(\d{3})$~', $path, $matches) === 1) {
    http_response_code((int) $matches[1]);
    header('Content-Type: application/json');
    echo json_encode(['jsonrpc' => '2.0', 'result' => 'status', 'id' => 1]);

    return true;
}

switch ($path) {
    case '/echo':
        header('Content-Type: application/json');
        echo json_encode([
            'jsonrpc' => '2.0',
            'result' => [
                'method' => $_SERVER['REQUEST_METHOD'],
                'body' => $body,
                'headers' => array_change_key_case(getallheaders()),
            ],
            'id' => 1,
        ]);

        return true;

    case '/cookies':
        header('Content-Type: application/json');
        header('Set-Cookie: session=abc=def; Path=/; HttpOnly');
        header('Set-Cookie: theme=dark; Path=/', false);
        echo json_encode(['jsonrpc' => '2.0', 'result' => 'cookies', 'id' => 1]);

        return true;

    case '/slow':
        sleep(2);
        echo json_encode(['jsonrpc' => '2.0', 'result' => 'slow', 'id' => 1]);

        return true;

    case '/redirect':
        http_response_code(302);
        header('Location: /echo');

        return true;

    case '/empty':
        http_response_code(204);

        return true;

    case '/not-json':
        header('Content-Type: text/plain');
        echo 'not json at all';

        return true;
}

http_response_code(404);
echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32601, 'message' => 'Method not found'], 'id' => null]);

return true;
