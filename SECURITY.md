# Security Policy

## Supported versions

| Version | Support |
|---------|---------|
| 2.0.x   | Bug fixes and security fixes |
| 1.5.x   | Security fixes only |
| < 1.5   | Unsupported |

## Reporting a vulnerability

Please report suspected vulnerabilities privately via GitHub Security Advisories
(the **Security** tab of this repository) rather than opening a public issue.

## Security model

This library is a thin JSON-RPC 2.0 transport. The server executes procedures that
**you** register, so the security of a deployment depends heavily on how it is wired up.
This document records what the library protects against on its own, and what remains
yours to configure.

## Secure by default in 2.0

The hardening that was opt-in in 1.5 is the default behaviour in 2.0.

- **Internal errors are masked.** An exception a procedure throws that the library does
  not recognize is answered as a generic `-32603 Internal error`, so database errors, file
  paths and stack context never reach a client. The `data` member of `-32602 Invalid
  params` answers is suppressed for the same reason, because an `InvalidArgumentException`
  raised by application code carries its message there.

  Intended client-facing errors are still expressed by throwing
  `JsonRPC\Exception\ResponseException`, which carries its own message, code and data, or
  by handling an exception yourself with `$server->withLocalException(MyException::class)`.
  `$server->withInternalErrorMasking(false)` restores the 1.x behaviour.

- **Batches are bounded.** A batch of more than 100 calls is rejected with `-32600`.
  Adjust with `$server->withBatchLimit($max)`, or `0` for no limit. Cap the raw request
  body at the web server or PHP layer as well (`post_max_size`, `client_max_body_size`, a
  reverse proxy limit), so oversized payloads are rejected before they are decoded.

- **Objects expose only what you list.** `withObject($instance, ['a', 'b'])` publishes
  exactly those two methods. In 1.x every public method of the instance and its parents
  became an RPC endpoint, so a class growing a helper grew the API surface with it. Magic
  methods and methods that do not exist are refused when they are registered.

- **Errors raised by PHP are contained.** The server catches `Throwable`, so a `TypeError`
  inside a procedure becomes a masked internal error instead of a fatal error and a blank
  response.

- **The built-in transports never follow a redirect.** A JSON-RPC endpoint is a fixed POST
  URL; following a redirect would resend the `Authorization` and `Cookie` headers to a
  location the server operator does not necessarily control. A `3xx` answer is reported as
  an error instead. Two things can change that, and both are yours to decide:
  `withTransportOptions()` passes raw options to the transport, redirect settings included,
  and an injected PSR-18 client applies its own policy (Guzzle and symfony/http-client
  follow redirects by default, so disable that on a client you inject).

- **Headers cannot be injected into a request.** A header name or value containing a
  carriage return, a line feed or a null byte is refused, so an application putting a
  value it received into `withHeaders()` or `withCookies()` cannot have extra headers
  smuggled into its request.

- **Cookies a server deletes are forgotten.** A `Set-Cookie` with an empty value or
  `Max-Age=0` removes the cookie from the jar, so a session is not sent again after a
  logout.

- **Credentials are redacted from logs.** `Authorization`, `Cookie`, `Set-Cookie` and
  `Proxy-Authorization` values are replaced by `[redacted]` before anything is handed to a
  PSR-3 logger.

- **Authentication is timing safe.** Credentials are compared as fixed-length digests with
  `hash_equals()`, so neither the existence of a username nor the length of a password can
  be told from the response time. Entries that are not strings can never match.

## Still yours to get right

1. **`REMOTE_ADDR` is trusted as-is.** `allowHosts()` matches the address PHP reports.
   Behind a reverse proxy that address is the proxy, so the allowlist is only meaningful
   if it is also enforced at the proxy. The library intentionally does **not** trust
   `X-Forwarded-For`. IPv4 and IPv6 addresses and CIDR ranges are both supported;
   anything that cannot be parsed never matches.

2. **Always use TLS for HTTP Basic authentication.** Credentials are Base64 encoded, not
   encrypted. The client verifies certificates by default; `withoutSslVerification()`
   turns that off and should not be used against anything but a test server.

3. **Procedures receive whatever the client sent.** Parameters are bound to the signature
   of your procedure, but their values are not validated beyond the types you declare.
   Treat them as untrusted input.
