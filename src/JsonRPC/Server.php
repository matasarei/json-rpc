<?php

declare(strict_types=1);

namespace JsonRPC;

use JsonException;
use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\Exception\AuthenticationFailureException;
use JsonRPC\Exception\InvalidJsonFormatException;
use JsonRPC\Exception\InvalidJsonRpcFormatException;
use JsonRPC\Exception\ResponseEncodingFailureException;
use JsonRPC\Server\ErrorResponseFactory;
use JsonRPC\Server\RequestHandler;
use JsonRPC\Server\ServerRequest;
use JsonRPC\Server\ServerResponse;
use JsonRPC\Validator\HostValidator;
use JsonRPC\Validator\UserValidator;
use Throwable;

/**
 * Answers JSON-RPC 2.0 requests.
 *
 *     $server = new Server();
 *     $server->getProcedureHandler()->withCallback('sum', fn(int $a, int $b) => $a + $b);
 *     $server->execute()->send();
 *
 * execute() returns the response instead of writing it out, so the server can
 * also be used inside a framework controller.
 */
final class Server
{
    private const ENCODING_OPTIONS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * @var list<string>
     */
    private array $hosts = [];

    /**
     * @var array<string, string>
     */
    private array $users = [];

    /**
     * Exceptions the application handles itself, registered with withLocalException().
     *
     * @var list<class-string>
     */
    private array $localExceptions = [];

    private int $batchLimit = 100;

    private bool $maskInternalErrors = true;

    private ?string $authenticationHeader = null;

    public function __construct(
        private readonly ProcedureHandler $procedureHandler = new ProcedureHandler(),
        private readonly MiddlewareHandler $middlewareHandler = new MiddlewareHandler(),
        private readonly HostValidator $hostValidator = new HostValidator(),
        private readonly UserValidator $userValidator = new UserValidator(),
    ) {
    }

    public function getProcedureHandler(): ProcedureHandler
    {
        return $this->procedureHandler;
    }

    public function getMiddlewareHandler(): MiddlewareHandler
    {
        return $this->middlewareHandler;
    }

    /**
     * Read the credentials from another header than the standard one, for
     * setups where the web server does not forward Authorization.
     *
     * The value is read like an Authorization header: "Basic <base64>", or the
     * base64 on its own, so a forwarded header works as it arrives.
     */
    public function withAuthenticationHeader(string $header): self
    {
        $this->authenticationHeader = $header === '' ? null : $header;

        return $this;
    }

    /**
     * Only answer clients from these addresses or CIDR ranges.
     *
     * @param list<string> $hosts
     */
    public function allowHosts(array $hosts): self
    {
        $this->hosts = $hosts;

        return $this;
    }

    /**
     * Only answer requests carrying one of these credentials.
     *
     * @param array<string, string> $users Passwords keyed by username
     */
    public function authentication(array $users): self
    {
        $this->users = $users;

        return $this;
    }

    /**
     * Do not relay this exception to the client: let it bubble out of execute().
     *
     * The 401 and 403 answers stay the server's job unless what is registered is
     * more specific than AuthenticationFailureException or AccessDeniedException,
     * so registering one of their ancestors does not take those answers away.
     *
     * @param class-string $exception
     */
    public function withLocalException(string $exception): self
    {
        $this->localExceptions[] = $exception;

        return $this;
    }

    /**
     * Reject batches larger than this, 0 for no limit.
     */
    public function withBatchLimit(int $limit): self
    {
        $this->batchLimit = $limit;

        return $this;
    }

    /**
     * Answer a generic internal error instead of relaying the message and code
     * of exceptions the library does not recognize. On by default.
     */
    public function withInternalErrorMasking(bool $enabled = true): self
    {
        $this->maskInternalErrors = $enabled;

        return $this;
    }

    /**
     * @param ServerRequest|null $request Defaults to the current HTTP request
     *
     * @throws Throwable Exceptions registered with withLocalException()
     */
    public function execute(?ServerRequest $request = null): ServerResponse
    {
        $request ??= ServerRequest::fromGlobals();
        [$username, $password] = $request->credentials($this->authenticationHeader);
        $errorResponseFactory = new ErrorResponseFactory($this->maskInternalErrors);

        try {
            $this->hostValidator->validate($this->hosts, $request->remoteAddress());
            $this->userValidator->validate($this->users, $username, $password);

            $payload = $this->dispatch($this->decode($request->body), $username, $password, $errorResponseFactory);

            // Encoding happens inside the try: a result can still fail to encode,
            // for instance when a JsonSerializable of the application throws.
            return $this->respond($payload, $errorResponseFactory);
        } catch (Throwable $exception) {
            if ($this->isHandledByApplication($exception)) {
                throw $exception;
            }

            if ($exception instanceof AuthenticationFailureException) {
                return $this->unauthorized();
            }

            if ($exception instanceof AccessDeniedException) {
                return $this->forbidden();
            }

            return new ServerResponse($this->encodeSafely([
                'jsonrpc' => '2.0',
                'error' => $errorResponseFactory->create($exception),
                'id' => null,
            ]));
        }
    }

    /**
     * @return array<array-key, mixed>|null The payload to answer, null when there is nothing to say
     *
     * @throws Throwable
     */
    private function dispatch(
        mixed $payload,
        ?string $username,
        ?string $password,
        ErrorResponseFactory $errorResponseFactory,
    ): ?array {
        $handler = new RequestHandler(
            $this->procedureHandler,
            $this->middlewareHandler,
            $errorResponseFactory,
            // Authentication and access failures are answered by execute() with
            // a status code, so they have to leave the handler untouched.
            [
                ...$this->localExceptions,
                AuthenticationFailureException::class,
                AccessDeniedException::class,
            ],
        );

        if (!is_array($payload) || !array_is_list($payload) || $payload === []) {
            return $handler->handle($payload, $username, $password);
        }

        if ($this->batchLimit > 0 && count($payload) > $this->batchLimit) {
            throw new InvalidJsonRpcFormatException('Batch size limit exceeded');
        }

        $responses = [];

        foreach ($payload as $request) {
            $response = $handler->handle($request, $username, $password);

            if ($response !== null) {
                $responses[] = $response;
            }
        }

        return $responses === [] ? null : $responses;
    }

    /**
     * @throws InvalidJsonFormatException
     */
    private function decode(string $body): mixed
    {
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidJsonFormatException('Malformed payload', 0, $exception);
        }
    }

    /**
     * @param array<array-key, mixed>|null $payload
     */
    private function respond(?array $payload, ErrorResponseFactory $errorResponseFactory): ServerResponse
    {
        // Every request was a notification: there is nothing to answer.
        if ($payload === null) {
            return new ServerResponse('', 204, []);
        }

        // Responses of a batch are encoded one by one, so that a single result
        // that cannot be encoded does not take the whole batch down with it.
        // An access failure is not a per-response error: like the same exception
        // thrown by a procedure, it answers the whole batch with a status code.
        if (array_is_list($payload)) {
            $responses = [];

            foreach ($payload as $response) {
                $responses[] = $this->encodeResponse($response, $errorResponseFactory);
            }

            return new ServerResponse('[' . implode(',', $responses) . ']');
        }

        return new ServerResponse($this->encodeResponse($payload, $errorResponseFactory));
    }

    /**
     * Whether the application asked to handle this exception itself.
     *
     * Registering an exception the server answers with a status code, or one of
     * its ancestors, does not take that answer away: only something more
     * specific than AuthenticationFailureException or AccessDeniedException
     * replaces the 401 and 403 they produce.
     */
    private function isHandledByApplication(Throwable $exception): bool
    {
        $answeredWithAStatusCode = $exception instanceof AuthenticationFailureException
            || $exception instanceof AccessDeniedException;

        foreach ($this->localExceptions as $localException) {
            if (!$exception instanceof $localException) {
                continue;
            }

            if (
                $answeredWithAStatusCode
                && !is_subclass_of($localException, AuthenticationFailureException::class)
                && !is_subclass_of($localException, AccessDeniedException::class)
            ) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function encodeResponse(mixed $response, ErrorResponseFactory $errorResponseFactory): string
    {
        try {
            return json_encode($response, self::ENCODING_OPTIONS);
        } catch (Throwable $exception) {
            // Encoding a result runs application code, so it can raise what the
            // application asked to handle itself, and what the server answers
            // with a status code.
            if (
                $this->isHandledByApplication($exception)
                || $exception instanceof AuthenticationFailureException
                || $exception instanceof AccessDeniedException
            ) {
                throw $exception;
            }

            $id = is_array($response) ? $response['id'] ?? null : null;
            $error = $errorResponseFactory->create(new ResponseEncodingFailureException($exception->getMessage()));

            // The id has already been validated, this only makes sure the
            // answer to a failed encoding cannot fail to encode in turn.
            $id = is_int($id) || is_string($id) || (is_float($id) && is_finite($id)) ? $id : null;

            return $this->encodeSafely(['jsonrpc' => '2.0', 'error' => $error, 'id' => $id]);
        }
    }

    /**
     * Encode a response that must not fail to encode.
     *
     * The payload holds an integer, an identifier that has been validated and
     * text coming from an exception, which is the only part that can be
     * malformed, so invalid byte sequences are replaced rather than refused.
     *
     * @param array<string, mixed> $payload
     */
    private function encodeSafely(array $payload): string
    {
        return (string) json_encode(
            $payload,
            (self::ENCODING_OPTIONS & ~JSON_THROW_ON_ERROR) | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    private function unauthorized(): ServerResponse
    {
        return new ServerResponse(
            (string) json_encode([
                'jsonrpc' => '2.0',
                'error' => ['code' => 401, 'message' => 'Unauthorized'],
                'id' => null,
            ], self::ENCODING_OPTIONS),
            401,
            [
                'Content-Type' => 'application/json',
                'WWW-Authenticate' => 'Basic realm="JsonRPC"',
            ],
        );
    }

    private function forbidden(): ServerResponse
    {
        return new ServerResponse(
            (string) json_encode([
                'jsonrpc' => '2.0',
                'error' => ['code' => 403, 'message' => 'Forbidden'],
                'id' => null,
            ], self::ENCODING_OPTIONS),
            403,
        );
    }
}
