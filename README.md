JSON-RPC PHP Client and Server
=============================

[![CI workflow](https://github.com/matasarei/json-rpc/actions/workflows/main.yml/badge.svg)](https://github.com/matasarei/json-rpc/actions/workflows/main.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/fguillot/json-rpc.svg)](https://packagist.org/packages/fguillot/json-rpc)
[![Total Downloads](https://img.shields.io/packagist/dt/fguillot/json-rpc.svg)](https://packagist.org/packages/fguillot/json-rpc)
[![PHP Version](https://img.shields.io/packagist/php-v/fguillot/json-rpc.svg)](https://packagist.org/packages/fguillot/json-rpc)
[![License](https://img.shields.io/packagist/l/fguillot/json-rpc.svg)](LICENSE)

A simple JSON-RPC client/server that just works.

Project status
--------------

This repository is the maintained continuation of the original
[fguillot/JsonRPC](https://packagist.org/packages/fguillot/json-rpc) library, which was
abandoned and removed from GitHub by its original author. The package keeps its original
name `fguillot/json-rpc` on Packagist so existing installations keep working; this
repository (`matasarei/json-rpc`) is the canonical source. The library is in maintenance
mode: it receives bug fixes, security fixes and compatibility updates for new PHP versions.

Features
--------

- JSON-RPC 2.0 only
- Client and server for batch requests and notifications
- HTTP Basic authentication and IP-based client restrictions
- Custom middleware
- PSR-3 logging of requests and responses (with credential redaction)
- No hard runtime dependency beyond `ext-json` and `psr/log`
- Works with the `curl` extension or, as a fallback, plain PHP streams
- Fully unit tested, statically analysed (PHPStan) and PSR-12 compliant
- Requires PHP 8.0+
- License: MIT

Contributors
------
[Frédéric Guillot](https://github.com/fguillot) and many others:

![Contributors](https://contrib.rocks/image?repo=matasarei/json-rpc)

Requirements
------------

- PHP 8.0 or later
- `ext-json`
- `ext-curl` is optional; when it is not available the client transparently falls back to PHP streams

Installation with Composer
--------------------------
```bash
composer require fguillot/json-rpc
```

Examples
--------

- [Server](#server)
- [Client](#client)
- [Client batch requests](#client-batch-requests)
- [Client notifications](#client-notifications)
- [Client exceptions](#client-exceptions)
- [Client logging and debugging](#client-logging-and-debugging)
- [IP based client restrictions](#ip-based-client-restrictions)
- [HTTP Basic Authentication](#http-basic-authentication)
- [Local Exceptions](#local-exceptions)
- [Production hardening](#production-hardening)
- [Callback before client request](#callback-before-client-request)

### Symfony
* https://github.com/matasarei/json-rpc-demo


### Server

Callback binding:

```php
<?php

use JsonRPC\Server;

$server = new Server();
$server->getProcedureHandler()
    ->withCallback('addition', function ($a, $b) {
        return $a + $b;
    })
    ->withCallback('random', function ($start, $end) {
        return mt_rand($start, $end);
    })
;

echo $server->execute();
```

Callback binding from array:

```php
<?php

use JsonRPC\Server;

$callbacks = [
    'getA' => function() { return 'A'; },
    'getB' => function() { return 'B'; },
    'getC' => function() { return 'C'; }
];

$server = new Server();
$server->getProcedureHandler()->withCallbackArray($callbacks);

echo $server->execute();
```

Class/Method binding:

```php
<?php

use JsonRPC\Server;

class Api
{
    public function doSomething($arg1, $arg2 = 3)
    {
        return $arg1 + $arg2;
    }
}

$server = new Server();
$procedureHandler = $server->getProcedureHandler();

// Bind the method Api::doSomething() to the procedure myProcedure
$procedureHandler->withClassAndMethod('myProcedure', 'Api', 'doSomething');

// Use a class instance instead of the class name
$procedureHandler->withClassAndMethod('mySecondProcedure', new Api, 'doSomething');

// The procedure and the method are the same
$procedureHandler->withClassAndMethod('doSomething', 'Api');

// Attach the class, the client will be able to call directly Api::doSomething()
$procedureHandler->withObject(new Api());

echo $server->execute();
```

Class/Method binding from array:

```php
<?php

use JsonRPC\Server;

class MathApi
{
    public function addition($arg1, $arg2)
    {
        return $arg1 + $arg2;
    }

    public function subtraction($arg1, $arg2)
    {
        return $arg1 - $arg2;
    }

    public function multiplication($arg1, $arg2)
    {
        return $arg1 * $arg2;
    }

    public function division($arg1, $arg2)
    {
        return $arg1 / $arg2;
    }
}

$callbacks = [
    'addition'       => [ 'MathApi', addition ],
    'subtraction'    => [ 'MathApi', subtraction ],
    'multiplication' => [ 'MathApi', multiplication ,
    'division'       => [ 'MathApi', division ],
];

$server = new Server();
$server->getProcedureHandler()->withClassAndMethodArray($callbacks);

echo $server->execute();
```

Server Middleware:

Middleware might be used to authenticate and authorize the client.
They are executed before each procedure.

```php
<?php

use JsonRPC\Server;
use JsonRPC\MiddlewareInterface;
use JsonRPC\Exception\AuthenticationFailureException;

class Api
{
    public function doSomething($arg1, $arg2 = 3)
    {
        return $arg1 + $arg2;
    }
}

class MyMiddleware implements MiddlewareInterface
{
    public function execute($username, $password, $procedureName)
    {
        if ($username !== 'foobar') {
            throw new AuthenticationFailureException('Wrong credentials!');
        }
    }
}

$server = new Server();
$server->getMiddlewareHandler()->withMiddleware(new MyMiddleware());
$server->getProcedureHandler()->withObject(new Api());
echo $server->execute();
```

You can raise a `AuthenticationFailureException` when the API credentials are wrong or a `AccessDeniedException` when the user is not allowed to access to the procedure.

### Client

Example with positional parameters:

```php
<?php

use JsonRPC\Client;

$client = new Client('http://localhost/server.php');
$result = $client->execute('addition', [3, 5]);
```

Example with named arguments:

```php
<?php

use JsonRPC\Client;

$client = new Client('http://localhost/server.php');
$result = $client->execute('random', ['end' => 10, 'start' => 1]);
```

Arguments are called in the right order.

Examples with the magic method `__call()`:

```php
<?php

use JsonRPC\Client;

$client = new Client('http://localhost/server.php');
$result = $client->random(50, 100);
```

The example above use positional arguments for the request and this one use named arguments:

```php
$result = $client->random(['end' => 10, 'start' => 1]);
```

### Client batch requests

Call several procedures in a single HTTP request:

```php
<?php

use JsonRPC\Client;

$client = new Client('http://localhost/server.php');

$results = $client->batch()
                  ->foo(['arg1' => 'bar'])
                  ->random(1, 100)
                  ->add(4, 3)
                  ->execute('add', [2, 5])
                  ->send();

print_r($results);
```

All results are stored at the same position of the call.

### Client notifications

A notification is a request without an `id` member: the server executes the
procedure but does not send any response back.

```php
<?php

use JsonRPC\Client;

$client = new Client('http://localhost/server.php');
$client->notify('logEvent', ['event' => 'user_login']);
```

Notifications can also be mixed into a batch request; only the regular calls
produce results:

```php
$results = $client->batch()
                  ->execute('add', [2, 5])
                  ->notify('logEvent', ['event' => 'addition'])
                  ->send();
```

### Client exceptions

Client exceptions are normally thrown when an error is returned by the server. You can change this behaviour by
using the `$returnException` argument which causes exceptions to be returned. This can be extremely useful when
executing the batch request. 

- `BadFunctionCallException`: Procedure not found on the server
- `InvalidArgumentException`: Wrong procedure arguments
- `JsonRPC\Exception\AccessDeniedException`: Access denied
- `JsonRPC\Exception\ConnectionFailureException`: Connection failure
- `JsonRPC\Exception\ServerErrorException`: Internal server error

### Client logging and debugging

The HTTP client accepts any [PSR-3](https://www.php-fig.org/psr/psr-3/) logger and
logs the JSON request and response (with `debug` level) through it:

```php
<?php

use JsonRPC\Client;

$client = new Client('http://localhost/server.php');
$client->getHttpClient()->withLogger($myPsr3Logger); // e.g. a Monolog instance
```

Sensitive headers (`Authorization`, `Cookie`, `Proxy-Authorization`) are redacted
before logging.

If you do not use a logging framework, the legacy debug mode writes the same
messages to the PHP system logger (configurable via `error_log` in `php.ini`):

```php
$client->getHttpClient()->withDebug(); // deprecated, prefer withLogger()
```

### IP based client restrictions

The server can allow only some IP addresses:

```php
<?php

use JsonRPC\Server;

$server = new Server;

// IP client restrictions
$server->allowHosts(['192.168.0.1', '127.0.0.1']);

...

// Return the response to the client
echo $server->execute();
```

If the client is blocked, you got a 403 Forbidden HTTP response.

### HTTP Basic Authentication

If you use HTTPS, you can allow client by using a username/password.

```php
<?php

use JsonRPC\Server;

$server = new Server;

// List of users to allow
$server->authentication(['user1' => 'password1', 'user2' => 'password2']);

...

// Return the response to the client
echo $server->execute();
```

On the client, set credentials like that:

```php
<?php

use JsonRPC\Client;

$client = new Client('http://localhost/server.php');
$client->getHttpClient()
    ->withUsername('Foo')
    ->withPassword('Bar');
```

If the authentication failed, the client throw a RuntimeException.

Using an alternative authentication header:

```php

use JsonRPC\Server;

$server = new Server();
$server->setAuthenticationHeader('X-Authentication');
$server->authentication(['myusername' => 'mypassword']);
```

The example above will use the HTTP header `X-Authentication` instead of the standard `Authorization: Basic [BASE64_CREDENTIALS]`.
The username/password values need be encoded in base64: `base64_encode('username:password')`.

### Local Exceptions

By default, the server will relay all exceptions to the client.
If you would like to relay only some of them, use the method `Server::withLocalException($exception)`:

```php
<?php

use JsonRPC\Server;
class MyException1 extends Exception {};
class MyException2 extends Exception {};

$server = new Server();

// Exceptions that should NOT be relayed to the client, if they occurs
$server
    ->withLocalException('MyException1')
    ->withLocalException('MyException2')
;

...

echo $server->execute();
```

### Production hardening

Two opt-in server options are recommended when exposing the server publicly. Both default
to the previous behaviour, so they never change existing deployments unless you enable them.

Hide internal exception details from clients — any exception that is not a JSON-RPC exception
(and not registered as a local exception) is returned as a generic `-32603 Internal error`
instead of leaking its message (database errors, file paths, stack context):

```php
<?php

use JsonRPC\Server;

$server = new Server();
$server->withInternalErrorMasking();
```

You can still return intentional, client-facing errors by throwing
`JsonRPC\Exception\ResponseException`, which carries its own message, code and data.

Limit the number of calls accepted in a single batch to mitigate denial-of-service; larger
batches are rejected with `-32600 Invalid Request`:

```php
$server->withBatchLimit(50);
```

See [SECURITY.md](SECURITY.md) for the full security model and hardening guidance.

### Callback before client request

You can use a callback to change the HTTP headers or the URL before to make the request to the server.

Example:

```php
<?php

$client = new Client();
$client->getHttpClient()->withBeforeRequestCallback(function(HttpClient $client, $payload) {
    $client->withHeaders(['Content-Length: '.strlen($payload)]);
});

$client->myProcedure(123);
```

Development
-----------

Install the dependencies and run the checks:

```bash
composer install
vendor/bin/phpunit                            # unit tests
vendor/bin/phpcs                              # coding standard (PSR-12)
vendor/bin/phpstan analyse --memory-limit=512M # static analysis
```
