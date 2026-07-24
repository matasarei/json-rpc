# Changelog

## v1.5.0 (unreleased)

### Added
- PSR-3 logging support: `HttpClient::withLogger()` accepts any `Psr\Log\LoggerInterface`
  and receives request/response debug messages. `Authorization`, `Cookie` and
  `Proxy-Authorization` header values are redacted before logging.
- Client-side notifications: `Client::notify()` sends a request without an `id` member
  (the server must not reply), also usable inside `batch()`. Batches consisting only of
  notifications no longer fail on the empty server response.
- `RequestBuilder::asNotification()` builds a payload without an `id` member.
- PHPStan static analysis (level 4 with a baseline for pre-existing findings) in CI.

### Fixed
- HTTP error responses from HTTP/2 servers (status lines like `HTTP/2 500` without a
  reason phrase) were silently ignored; status lines are now parsed for any HTTP version.
- cURL transport did not follow redirects at all (`CURLOPT_MAXREDIRS` without
  `CURLOPT_FOLLOWLOCATION`), while the stream transport did. Both now follow up to 2 redirects.
- cURL transport had no total request timeout (only a connect timeout); `withTimeout()`
  now also sets `CURLOPT_TIMEOUT`, matching the stream transport semantics.
- Cookie values containing `=` were dropped, and cookie attributes (`Path`, `Expires`, ...)
  were stored as if they were cookies.
- `Server::setAuthenticationHeader()` broke on passwords containing `:` and emitted an
  error on malformed (non-base64 or separator-less) header values.
- Passing an explicit `id` of `0` to `RequestBuilder` no longer replaces it with a random id.
- Basic auth password comparison on the server is now timing-safe (`hash_equals()`).

### Changed
- Composer autoloading switched from deprecated PSR-0 to PSR-4.
- New dependency: `psr/log` (`^1.1 || ^2.0 || ^3.0`).
- CI: PHP 8.5 added to the test matrix, actions updated.

### Deprecated
- `HttpClient::withDebug()` — use `withLogger()` with any PSR-3 logger instead.
  Debug mode now also redacts credentials instead of writing them to the error log.
