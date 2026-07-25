Upgrading from 1.x to 2.0
=========================

Version 2.0 modernizes the library without changing what it feels like to use: the client
still calls procedures as methods, the server still hands out a procedure handler and a
middleware handler, and the namespace and package name are unchanged.

What did change is everything that made the library hard to test, hard to embed in a
framework, and unsafe by default. This page lists every break and what to write instead.

- [Requirements](#requirements)
- [Server bootstrap](#server-bootstrap)
- [Server inside a framework](#server-inside-a-framework)
- [Registering procedures](#registering-procedures)
- [Middleware](#middleware)
- [Client](#client)
- [Batch requests](#batch-requests)
- [HTTP client and transports](#http-client-and-transports)
- [Exceptions](#exceptions)
- [Behaviour changes](#behaviour-changes)
- [Removed classes](#removed-classes)

Requirements
------------

| | 1.x | 2.0 |
|---|---|---|
| PHP | >= 8.0 | >= 8.4 |
| psr/log | ^1.1 \|\| ^2.0 \|\| ^3.0 | ^3.0 |
| ext-json | required | dropped (bundled since PHP 8.0) |

Every file declares `strict_types=1`, so arguments are no longer coerced: passing `"5"`
where an `int` is declared now raises a `TypeError`.

Server bootstrap
----------------

The server no longer reads `php://input` in its constructor, and no longer calls
`header()` while it runs. `execute()` returns a response object.

```php
// 1.x
$server = new Server();
$server->getProcedureHandler()->withCallback('sum', $callback);
echo $server->execute();

// 2.0
$server = new Server();
$server->getProcedureHandler()->withCallback('sum', $callback);
$server->execute()->send();
```

Passing a payload explicitly moved from the constructor to `execute()`:

```php
// 1.x
$server = new Server($jsonString, $serverVariables);

// 2.0
use JsonRPC\Server\ServerRequest;

$server->execute(ServerRequest::fromString($jsonString, $serverVariables));
```

`ServerResponse` exposes `body`, `statusCode` and `headers`; `send()` writes them out.
This is also the fix for a 1.x bug: 401 and 403 headers were set on internal response
builders that were thrown away, so they never reached the client.

Server inside a framework
-------------------------

```php
// 2.0, Symfony controller
$rpcResponse = $server->execute(
    ServerRequest::fromString($request->getContent(), $request->server->all()),
);

return new Response($rpcResponse->body, $rpcResponse->statusCode, $rpcResponse->headers);
```

Registering procedures
----------------------

| 1.x | 2.0 |
|---|---|
| `$server->register('sum', $callback)` | `$server->getProcedureHandler()->withCallback('sum', $callback)` |
| `$server->bind('sum', Api::class, 'sum')` | `$server->getProcedureHandler()->withClassAndMethod('sum', Api::class, 'sum')` |
| `$server->attach(new Api())` | `$server->getProcedureHandler()->withObject(new Api(), ['method1', 'method2'])` |

`register()`, `bind()` and `attach()` were deprecated in 1.x and are removed.

`withObject()` now requires the list of methods to expose. In 1.x every public method of
the object became a procedure, so adding a helper to a class silently published it.
Listing unknown or magic methods raises an `InvalidArgumentException` at registration.

Classes registered by name are still instantiated with `new`. To build them differently,
for instance through a container:

```php
$server->getProcedureHandler()->withInstanceFactory($container->get(...));
```

`setAuthenticationHeader()` is now `withAuthenticationHeader()`, and is read when the
request is handled instead of when it is configured.

Middleware
----------

```php
// 1.x
public function execute($username, $password, $procedureName)

// 2.0
public function execute(?string $username, ?string $password, string $procedureName): void
```

Missing credentials are `null` instead of an empty string. `MiddlewareHandler` no longer
has `withUsername()`, `withPassword()` and `withProcedure()`: the context is passed to
`execute()`, so nothing is shared between the requests of a batch any more.

Client
------

```php
// 1.x
$client = new Client($url, $returnException, $httpClient);

// 2.0
$client = new Client($url, $httpClient, $idGenerator);
```

The `$returnException` mode is gone: a failed call always throws. If you used it to
inspect errors, catch the exception instead.

`notify()` returns `void` (it returned `null`), and `execute()` takes the same arguments
as before.

Batch requests
--------------

```php
// 1.x
$results = $client->batch()
    ->random(1, 100)
    ->add(4, 3)
    ->send();

// 2.0: identical, but batch() returns a BatchBuilder
```

What changed underneath:

- Answers are matched to calls **by request id** instead of by position. A server
  answering out of order (which the specification allows) used to return results assigned
  to the wrong calls.
- A builder can only be sent once. In 1.x the payload was never cleared, so calling
  `send()` twice re-sent the whole batch.
- A batch of notifications returns `[]` instead of `null`.
- A failure throws `BatchFailedException` instead of mixing exception objects into the
  result array:

```php
try {
    $results = $client->batch()->add(4, 3)->missing()->send();
} catch (BatchFailedException $e) {
    $e->getResults(); // results of the calls that succeeded, keyed by call position
    $e->getErrors();  // exceptions of the calls that failed, keyed by call position
}
```

HTTP client and transports
--------------------------

I/O moved behind `TransportInterface`, which is what makes
[#21](https://github.com/matasarei/json-rpc/issues/21) possible:

```php
use JsonRPC\Transport\Psr18Transport;

$client = new Client($url, new HttpClient($url, new Psr18Transport($psr18Client)));
```

| 1.x | 2.0 |
|---|---|
| `withDebug()` | `withLogger($psr3Logger)` |
| `addOption($option, $value)` / `setOptions($options)` | `withTransportOptions([$option => $value])` |
| `withSslLocalCert($path)` | `withCaFile($path)` or `withLocalCert($path)` |
| `withHeaders(['Name: value'])` | `withHeaders(['Name' => 'value'])` |
| `withCookies()` returned `void` | returns `$this` |
| `handleExceptions()` was public | internal |

`withSslLocalCert()` was ambiguous: it set a certificate authority bundle on the cURL
path but a client certificate on the stream path. The two settings are now separate and
behave the same on both.

Connection settings raise a `LogicException` when a transport was injected, instead of
being silently ignored. Configure the transport itself in that case.

Per-request headers, on both `Client::execute()` and `BatchBuilder::send()`, are maps
(`['X-Name' => 'value']`) rather than lists of raw header lines.

Exceptions
----------

- Every exception implements `JsonRPC\Exception\JsonRpcException`, so `catch
  (JsonRpcException $e)` catches anything the library throws.
- `RpcCallFailedException` extends `RuntimeException` instead of `Exception`; it is still
  an `Exception`, so existing catch blocks keep working.
- Error code -32601 throws `MethodNotFoundException` and -32602 throws
  `InvalidParamsException`. They extend `BadFunctionCallException` and
  `InvalidArgumentException`, which is what 1.x threw, so both existing catch blocks and
  procedures throwing the SPL exceptions keep working.
- `InvalidJsonFormatException` and `InvalidJsonRpcFormatException` now extend
  `ResponseException`.
- `ResponseException::setData()` is removed; pass the data to the constructor. `getData()`
  and the readonly `$data` property are available.

Behaviour changes
-----------------

| Change | 1.x | 2.0 |
|---|---|---|
| Internal error masking | off | **on** — `withInternalErrorMasking(false)` to relay exception messages |
| Batch size limit | unlimited | **100** — `withBatchLimit(0)` for no limit |
| Request made only of notifications | HTTP 200, empty body | **HTTP 204**, empty body |
| Errors raised by PHP (`TypeError`, ...) | fatal error | caught, answered as `-32603` |
| `HostValidator` | IPv4 only | IPv4 and IPv6, including CIDR ranges |
| Request ids | `mt_rand()` | `random_int()`, through an injectable generator |
| Masked `-32602` responses | leaked the message in `data` | `data` suppressed while masking is on |

Removed classes
---------------

| Removed | Replacement |
|---|---|
| `JsonRPC\Request\RequestParser` | `JsonRPC\Server\RequestHandler` |
| `JsonRPC\Request\BatchRequestParser` | batch handling inside `JsonRPC\Server` |
| `JsonRPC\Response\ResponseBuilder` | `JsonRPC\Server\ErrorResponseFactory` and `ServerResponse` |
| `JsonRPC\Validator\JsonFormatValidator` | `JSON_THROW_ON_ERROR` |
| `JsonRPC\Validator\RpcFormatValidator` | shape check inside `RequestHandler` |
| `JsonRPC\Validator\JsonEncodingValidator` | `JSON_THROW_ON_ERROR` |
| `JsonRPC\Logger\ErrorLogLogger` | any PSR-3 logger |

`HostValidator` and `UserValidator` are still there but are instance classes with instance
methods, not static utilities. All the `static create()` factories are gone: construct the
objects.
