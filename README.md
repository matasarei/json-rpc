JSON-RPC PHP Client and Server
=============================

[![CI workflow](https://github.com/matasarei/json-rpc/actions/workflows/main.yml/badge.svg?branch=2.x)](https://github.com/matasarei/json-rpc/actions/workflows/main.yml?query=branch%3A2.x)
[![Latest Stable Version](https://img.shields.io/packagist/v/fguillot/json-rpc.svg)](https://packagist.org/packages/fguillot/json-rpc)
[![Latest Beta](https://img.shields.io/packagist/v/fguillot/json-rpc?include_prereleases&label=beta)](https://github.com/matasarei/json-rpc/releases)
[![Total Downloads](https://img.shields.io/packagist/dt/fguillot/json-rpc.svg)](https://packagist.org/packages/fguillot/json-rpc)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.4-777bb3?logo=php&logoColor=white)](https://www.php.net/supported-versions.php)
[![PHPStan](https://img.shields.io/badge/PHPStan-max-brightgreen)](phpstan.neon)
[![Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen)](.github/workflows/main.yml)
[![License](https://img.shields.io/packagist/l/fguillot/json-rpc.svg)](LICENSE)

A simple JSON-RPC client/server that just works.

Project status
--------------

This repository is the maintained continuation of the original
[fguillot/JsonRPC](https://packagist.org/packages/fguillot/json-rpc) library, which was
abandoned and removed from GitHub by its original author. The package keeps its original
name `fguillot/json-rpc` on Packagist so existing installations keep working; this
repository (`matasarei/json-rpc`) is the canonical source.

**Version 2.0 is a rewrite.** The API kept its shape, but there are breaking changes:
see [UPGRADE-2.0.md](UPGRADE-2.0.md) for the complete v1 to v2 mapping. The 1.5 branch
still receives security fixes.

Features
--------

- JSON-RPC 2.0 only
- Client and server for batch requests and notifications
- Batch answers correlated by request id, in call order
- Any [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client can be plugged in, or the
  built-in cURL and stream transports can be used without any extra dependency
- Secure by default: internal errors masked, batch size limited, explicit method allowlists
- HTTP Basic authentication and IP based client restrictions (IPv4 and IPv6)
- Custom middleware
- PSR-3 logging of requests and responses, with credential redaction
- Framework friendly: the server takes a request object and returns a response object
- `declare(strict_types=1)` everywhere, PHPStan at max level, 100% test coverage
- Requires PHP 8.4+
- License: MIT

Contributors
------
[Frédéric Guillot](https://github.com/fguillot) and many others:

![Contributors](https://contrib.rocks/image?repo=matasarei/json-rpc)

Requirements
------------

- PHP 8.4 or later
- `psr/log` 3.0
- `ext-curl` is optional; without it the client falls back to PHP streams
- `psr/http-client` and `psr/http-factory` are optional, and only needed to send requests
  through a PSR-18 client

Installation with Composer
--------------------------
```bash
composer require fguillot/json-rpc
```

Table of contents
-----------------

- [Server](#server)
- [Server inside a framework](#server-inside-a-framework)
- [Client](#client)
- [Client batch requests](#client-batch-requests)
- [Client notifications](#client-notifications)
- [Client exceptions](#client-exceptions)
- [Using another HTTP client](#using-another-http-client)
- [Client logging and debugging](#client-logging-and-debugging)
- [IP based client restrictions](#ip-based-client-restrictions)
- [HTTP Basic Authentication](#http-basic-authentication)
- [Middleware](#middleware)
- [Local exceptions](#local-exceptions)
- [Security defaults](#security-defaults)
- [Callback before client request](#callback-before-client-request)
- [Development](#development)

### Server

```php
use JsonRPC\Server;

$server = new Server();

$server->getProcedureHandler()
    ->withCallback('addition', fn(int $a, int $b): int => $a + $b)
    ->withCallback('random', fn(int $start, int $end): int => random_int($start, $end));

$server->execute()->send();
```

`execute()` returns a `ServerResponse`; `send()` writes the status line, the headers and
the body out. Procedures can also be methods of a class:

```php
class Api
{
    public function doSomething(string $arg): string
    {
        return strtoupper($arg);
    }
}

// Bind one method to a procedure name, the class is instantiated when called
$server->getProcedureHandler()->withClassAndMethod('doSomething', Api::class);

// Or bind an instance, listing the methods that become procedures
$server->getProcedureHandler()->withObject(new Api(), ['doSomething']);
```

Unlike v1, the methods of an object have to be listed: only what you name is reachable.
When a class is registered by name it is instantiated with `new`, unless you say how:

```php
$server->getProcedureHandler()->withInstanceFactory($container->get(...));
```

A method called before every procedure of an object can be registered with
`withBeforeMethod('beforeProcedure')`; it receives the name of the method about to run.

### Server inside a framework

The server never reads `php://input` or calls `header()` on its own when you hand it a
request, so it drops into a controller:

```php
use JsonRPC\Server\ServerRequest;

// Symfony
public function rpc(Request $request, Server $server): Response
{
    $rpcResponse = $server->execute(
        ServerRequest::fromString($request->getContent(), $request->server->all()),
    );

    return new Response($rpcResponse->body, $rpcResponse->statusCode, $rpcResponse->headers);
}
```

A [demo application](https://github.com/matasarei/json-rpc-demo) is available.

### Client

```php
use JsonRPC\Client;

$client = new Client('http://localhost/server.php');

// Named arguments
$result = $client->random(['start' => 1, 'end' => 100]);

// Positional arguments
$result = $client->execute('random', [1, 100]);
```

To always send positional arguments, even when a single array is passed:

```php
$client = (new Client('http://localhost/server.php'))->withPositionalArguments();
```

### Client batch requests

```php
$results = $client->batch()
    ->random(1, 100)
    ->add(4, 3)
    ->send();

// [0 => 42, 1 => 7], in the order the calls were made
```

Results are keyed by the position of the call among those that expect an answer, so
notifications mixed into a batch do not leave holes in the array.

Answers are matched to calls by request id, so a server answering out of order (which the
specification allows) no longer mixes up results. A batch builder is single use: call
`batch()` again for a new one.

When at least one call of a batch fails, `send()` throws `BatchFailedException`, which
carries everything the batch produced:

```php
use JsonRPC\Exception\BatchFailedException;

try {
    $results = $client->batch()->add(4, 3)->missing()->send();
} catch (BatchFailedException $e) {
    $e->getResults(); // [0 => 7]                        results of the calls that succeeded
    $e->getErrors();  // [1 => MethodNotFoundException]  errors, on the same keys
}
```

### Client notifications

A notification is a call without an id: the server must not answer it.

```php
$client->notify('add_user', ['name' => 'Bob']);

$client->batch()
    ->notify('add_user', ['name' => 'Bob'])
    ->notify('add_user', ['name' => 'Alice'])
    ->send();
```

### Client exceptions

Every exception raised by a failed call implements `JsonRPC\Exception\JsonRpcException`,
so a single `catch` covers them all. Misuse of the library itself is reported separately,
with the usual SPL exceptions: `InvalidArgumentException` for a bad registration or an
unusable header, and `LogicException` for a call that does not make sense, such as sending
a batch twice.

| Exception | Raised when |
|---|---|
| `ConnectionFailureException` | the request did not complete, or the server answered 404 |
| `AccessDeniedException` | the server answered 401 or 403 |
| `ServerErrorException` | the server answered 500 with something other than a JSON-RPC error object |
| `ResponseException` | the server answered 3xx, or an error status carrying something that is not an answer, or the answer belongs to another request, or a batch left one of the calls unanswered |
| `InvalidJsonRpcFormatException` | error code -32600, or an answer with neither a result nor an error member |
| `MethodNotFoundException` | error code -32601 (extends `BadFunctionCallException`) |
| `InvalidParamsException` | error code -32602 (extends `InvalidArgumentException`) |
| `InvalidJsonFormatException` | error code -32700, or an answer that is not JSON |
| `ResponseException` | any other error object the server defines; `getCode()` and `getData()` carry its code and data |
| `BatchFailedException` | at least one call of a batch failed |

An error object the server sends is relayed rather than replaced by the status code
carrying it, 500 included. The exceptions are 3xx, 401, 403 and 404, which say something
about the request never reaching the procedure and are reported as they are.

Answers are checked before they are returned: one that carries another request's id, or
that is JSON without being an answer at all — a maintenance page in front of the endpoint,
for instance — is refused rather than returned as a `null` result. Inside a batch the same
check turns into an error for that call.

### Using another HTTP client

The bytes go over the wire through a `TransportInterface`. cURL is used when the
extension is available and PHP streams otherwise, but any PSR-18 client can be injected
instead:

```php
use JsonRPC\Client;
use JsonRPC\HttpClient;
use JsonRPC\Transport\Psr18Transport;
use Symfony\Component\HttpClient\Psr18Client;

$url = 'http://localhost/server.php';

// Symfony's Psr18Client also implements the PSR-17 factories
$client = new Client($url, new HttpClient($url, new Psr18Transport(new Psr18Client())));

// Clients that do not, such as Guzzle, take the factories separately
$client = new Client($url, new HttpClient($url, new Psr18Transport(
    new GuzzleHttp\Client(),
    new Nyholm\Psr7\Factory\Psr17Factory(),
    new Nyholm\Psr7\Factory\Psr17Factory(),
)));
```

Authentication, cookies, logging and the reading of status codes work the same on every
transport. Redirects are the exception: the built-in transports never follow one, and
neither does Guzzle through its PSR-18 entry point, but symfony/http-client follows them
unless its `max_redirects` option is set to `0`. Compression is another: the built-in transports
do not negotiate it, while a PSR-18 client that does will decode the body for you.

Connection settings configure the built-in transports, so they apply to a client that was
*not* given a transport of its own; on one that was, they raise a `LogicException` because
the injected transport carries its own configuration.

```php
$client = new Client('http://localhost/server.php');

$client->getHttpClient()
    ->withTimeout(5)            // connection timeout in seconds
    ->withExecutionTimeout(30)  // total transfer timeout, 0 for no limit
    ->withCaFile('/path/to/ca-bundle.pem')
    ->withLocalCert('/path/to/client-certificate.pem')
    ->withTransportOptions([CURLOPT_INTERFACE => 'eth0']);
```

`withTransportOptions()` is passed straight to the transport, so it can also override the
library's own defaults, redirect handling included.

### Client logging and debugging

```php
$client->getHttpClient()->withLogger($anyPsr3Logger);
```

Requests and responses are logged at debug level. `Authorization`, `Cookie`, `Set-Cookie`
and `Proxy-Authorization` values are replaced by `[redacted]`.

### IP based client restrictions

```php
$server->allowHosts(['192.168.1.10', '10.0.0.0/8', '2001:db8::/32', '::1']);
```

Clients that do not match get a 403 answer. Addresses and ranges can be IPv4 or IPv6;
anything that cannot be parsed never matches.

### HTTP Basic Authentication

```php
// Server
$server->authentication(['alice' => 'p4ssw0rd', 'bob' => 'sup3rs3cr3t']);

// Client
$client->authentication('alice', 'p4ssw0rd');
```

Requests without valid credentials are answered with 401 and a `WWW-Authenticate` header.
When the web server does not forward the `Authorization` header, read the credentials
from another one:

```php
$server->withAuthenticationHeader('X-Authorization');
```

The value is read the way `Authorization` is: `Basic <base64 of user:password>`, or the
base64 on its own, so forwarding the original header verbatim works.

### Middleware

Middleware runs before the procedure and rejects the call by throwing:

```php
use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\MiddlewareInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    public function execute(?string $username, ?string $password, string $procedureName): void
    {
        if (!$this->acl->isAllowed($username, $procedureName)) {
            throw new AccessDeniedException('Not allowed');
        }
    }
}

$server->getMiddlewareHandler()->withMiddleware(new AuthMiddleware());
```

### Local exceptions

An exception registered as local is not turned into a JSON-RPC error: it is thrown out of
`execute()` for the application to handle.

```php
$server->withLocalException(MyDomainException::class);
```

`AuthenticationFailureException` and `AccessDeniedException` are handled by the server
itself, as 401 and 403. Registering one of their ancestors, `RuntimeException` for
instance, does not take those answers away; registering something more specific, a
subclass of `AccessDeniedException`, does hand it to the application.

### Security defaults

Version 2 is secure by default; each of these can be adjusted.

| Default | Meaning | To change |
|---|---|---|
| Internal error masking is **on** | Exceptions the library does not recognize become a generic `-32603 Internal error`, so database errors and file paths do not reach clients | `withInternalErrorMasking(false)` |
| Batch limit is **100** | Larger batches are rejected with `-32600` | `withBatchLimit(0)` for no limit |
| Objects expose only listed methods | `withObject($instance, ['a', 'b'])` publishes exactly those two | list more methods |
| Redirects are never followed | A redirect would resend the `Authorization` and `Cookie` headers to the new location | `withTransportOptions()`, or the policy of an injected PSR-18 client |

See [SECURITY.md](SECURITY.md) for the full security model.

### Callback before client request

```php
$client->getHttpClient()->withBeforeRequestCallback(
    function (HttpClient $client, string $payload, array $headers): void {
        $client->withHeaders(['X-Request-Id' => bin2hex(random_bytes(8))]);
    },
);
```

### Development

Everything runs through composer scripts:

```bash
composer test      # PHPUnit
composer lint      # phpcs, PSR-12
composer stan      # PHPStan, max level
composer check     # all three
composer coverage  # PHPUnit with coverage, fails below 100%
```

The transport tests talk to a PHP built-in web server started by the suite, so no
network access is required.
