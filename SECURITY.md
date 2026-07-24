# Security Policy

## Reporting a vulnerability

Please report suspected vulnerabilities privately via GitHub Security Advisories
(the **Security** tab of this repository) rather than opening a public issue.

## Security model and hardening guidance

This library is a thin JSON-RPC 2.0 transport. The server executes procedures that
**you** register, so the security of a deployment depends heavily on how it is wired up.
The v1.5 release hardened several concrete issues (see `CHANGELOG.md`) and this document
records the audit findings and the recommended way to run the server safely.

### Fixed in v1.5

- **Redirect credential leakage (client).** The client no longer follows HTTP redirects,
  so `Authorization`/`Cookie` headers are never resent to a redirect target.
- **Magic-method invocation (server).** Procedure names starting with `__` can no longer
  be dispatched to objects registered with `withObject()`.
- **IP allowlist correctness (server).** `HostValidator` now fails closed on malformed
  input and uses strict comparisons.
- **Auth timing side channels (server).** Username lookup and password comparison are
  constant-time.

### Recommended hardening (application responsibility)

These are deliberately left to the integrator because a safe default would break backward
compatibility; they are candidates for secure-by-default behaviour in a future major version.

1. **Expose only intended procedures.** Prefer `getProcedureHandler()->withCallback()` or
   `->withClassAndMethod()`, which bind explicit names, over `->withObject($service)`.
   `withObject()` exposes **every** public method of the instance (and its parents) as an
   RPC endpoint. If you use it, attach a dedicated facade object that contains only the
   methods you intend to publish.

2. **Do not leak internal exception messages.** By default, an exception thrown inside a
   procedure that is not registered as a "local exception" has its message and code relayed
   to the client. To avoid leaking database errors, file paths or stack context, either:
   - register sensitive exception types with `$server->withLocalException(MyException::class)`
     so they are re-thrown and handled by your application instead of being serialized, or
   - throw `JsonRPC\Exception\ResponseException` with a deliberately safe, client-facing
     message for errors you *do* want to expose.

3. **Bound the request size.** The server reads the whole request body and a batch may
   contain an unbounded number of calls. Cap the request body at the web-server/PHP layer
   (`post_max_size`, `client_max_body_size`, a reverse-proxy limit) to prevent CPU/memory
   exhaustion from very large batches.

4. **IP allowlisting is IPv4-only and trusts `REMOTE_ADDR`.** CIDR matching supports IPv4
   only. Behind a reverse proxy, `REMOTE_ADDR` is the proxy's address, so the allowlist is
   only meaningful if enforced at the proxy. The library intentionally does **not** trust
   `X-Forwarded-For`.

5. **Always use TLS for HTTP Basic authentication.** Credentials are Base64-encoded, not
   encrypted.
