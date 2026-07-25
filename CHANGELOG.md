# Changelog

## v2.0.0 (unreleased)

A rewrite that keeps the shape of the API. See [UPGRADE-2.0.md](UPGRADE-2.0.md) for the
complete v1 to v2 mapping.

### Added
- **Pluggable transports**: HTTP I/O sits behind `TransportInterface`. `CurlTransport` and
  `StreamTransport` keep the library dependency free, and `Psr18Transport` sends requests
  through any PSR-18 client such as symfony/http-client or Guzzle (closes #21).
  Authentication, cookies, logging and error handling behave the same on all of them.
- **Framework friendly server**: `Server::execute(?ServerRequest)` returns a
  `ServerResponse` with a body, a status code and headers. `ServerRequest::fromGlobals()`
  keeps the one-line script usage; `ServerRequest::fromString()` is what a controller
  passes in.
- `BatchBuilder`: batches are collected by a single-use builder and their answers are
  correlated by request id. A partially failed batch throws `BatchFailedException`, which
  carries the results that succeeded and the errors keyed by call position.
- `HttpClient::withCaFile()`, `withLocalCert()` and `withTransportOptions()`.
- `ProcedureHandler::withInstanceFactory()` to build procedure classes through a container.
- `JsonRpcException` marker interface, `MethodNotFoundException`, `InvalidParamsException`,
  and the `ErrorCode` enum for the codes of the specification.
- IPv6 and IPv6 CIDR support in `HostValidator`.

### Changed
- Requires PHP 8.4, `psr/log` ^3.0, and declares `strict_types=1` everywhere.
- Secure by default: internal error masking is on, batches are limited to 100 calls,
  `withObject()` requires an explicit method allowlist, and the server catches `Throwable`.
- A request made only of notifications is answered with HTTP 204 and an empty body.
- Status codes and headers are returned as part of the response instead of being emitted
  with `header()` while the server runs.
- Request ids come from an injectable generator backed by `random_int()`.
- Test suite rewritten: no global function shadowing, PHPStan at max level without a
  baseline, and 100% line coverage enforced in CI.

### Removed
- `RequestParser`, `BatchRequestParser`, `ResponseBuilder`, the three format validators,
  `ErrorLogLogger`, every `static create()` factory, the deprecated `Server::register()`,
  `bind()` and `attach()`, the client `$returnException` mode, `HttpClient::withDebug()`,
  `addOption()`, `setOptions()` and `withSslLocalCert()`.

## v1.5.0 (2026-07-24)

### Added
- PSR-3 logging support: `HttpClient::withLogger()` accepts any `Psr\Log\LoggerInterface`
  and receives request/response debug messages. `Authorization`, `Cookie` and
  `Proxy-Authorization` header values are redacted before logging.
- `Server::withInternalErrorMasking()` (opt-in, default off): returns a generic
  `-32603 Internal error` for unrecognized exceptions instead of relaying their
  message/code to the client, and suppresses the `data` member of `-32602 Invalid params`
  errors (an application-thrown `InvalidArgumentException` carries its message there).
  Recommended for production to avoid information disclosure.
- `Server::withBatchLimit(int $max)` (opt-in, default unlimited): rejects batches larger
  than the limit with `-32600 Invalid Request`, mitigating denial-of-service from very
  large batches.
- Client-side notifications: `Client::notify()` sends a request without an `id` member
  (the server must not reply), also usable inside `batch()`. Batches consisting only of
  notifications no longer fail on the empty server response.
- `RequestBuilder::asNotification()` builds a payload without an `id` member.
- `HttpClient::withExecutionTimeout(int $seconds)`: total transfer timeout (cURL
  `CURLOPT_TIMEOUT`, stream `timeout`), separate from the connect timeout set by
  `withTimeout()`. Default 0 (no limit), preserving the previous behavior for
  long-running procedures. A cURL timeout now raises a `ConnectionFailureException`
  with an accurate "Operation timed out" message.
- PHPStan static analysis (level 4 with a baseline for pre-existing findings) in CI.

### Security
- The client no longer follows HTTP redirects on either transport. A JSON-RPC endpoint is
  a fixed POST URL, and following a redirect resent the `Authorization` and `Cookie` headers
  to the redirect target (potentially attacker-controlled). Both cURL (`CURLOPT_FOLLOWLOCATION`)
  and the stream fallback (`follow_location`) now have redirect-following disabled.
- Magic methods can no longer be invoked as procedures. A client could previously call
  `__construct` (and other `__`-prefixed methods) on an object registered with `withObject()`;
  procedure names starting with `__` are now rejected.
- `HostValidator` IP/CIDR matching now fails closed on malformed input (non-IPv4 address,
  out-of-range or non-numeric mask) instead of matching by accident or throwing an
  uncaught `ArithmeticError`; comparisons use strict equality. IPv6 clients are correctly
  rejected by IPv4 CIDR rules (the library remains IPv4-only for CIDR — see SECURITY.md).
- Basic-auth username lookup is now constant-time regardless of whether the username exists,
  removing a username-enumeration timing side channel (in addition to the timing-safe
  password comparison already added).

### Fixed
- HTTP error responses from HTTP/2 servers (status lines like `HTTP/2 500` without a
  reason phrase) were silently ignored; status lines are now parsed for any HTTP version.
- Since redirects are no longer followed, a `3xx` response without a valid JSON body now
  raises a `ResponseException` instead of being silently ignored (which dropped
  notifications and produced a misleading "Malformed payload" error for regular calls).
- Cookie values containing `=` were dropped, and cookie attributes (`Path`, `Expires`, ...)
  were stored as if they were cookies.
- `Server::setAuthenticationHeader()` broke on passwords containing `:` and emitted an
  error on malformed (non-base64 or separator-less) header values.
- A username or password equal to the string `"0"` was discarded by `Server::getUsername()`
  / `getPassword()` (truthiness check) and silently replaced by the `PHP_AUTH_*` values.
- Passing an explicit `id` of `0` to `RequestBuilder` no longer replaces it with a random id.
- Basic auth password comparison on the server is now timing-safe (`hash_equals()`).

### Changed
- Composer autoloading switched from deprecated PSR-0 to PSR-4.
- New dependency: `psr/log` (`^1.1 || ^2.0 || ^3.0`).
- CI: PHP 8.5 added to the test matrix, actions updated.

### Deprecated
- `HttpClient::withDebug()` — use `withLogger()` with any PSR-3 logger instead.
  Debug mode now also redacts credentials instead of writing them to the error log.
