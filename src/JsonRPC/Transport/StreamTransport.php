<?php

declare(strict_types=1);

namespace JsonRPC\Transport;

use JsonRPC\Exception\ConnectionFailureException;
use ValueError;

/**
 * Fallback transport built on stream wrappers, used when cURL is unavailable.
 */
final class StreamTransport implements TransportInterface
{
    public function __construct(private readonly TransportOptions $options = new TransportOptions())
    {
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $context = stream_context_create($this->buildContextOptions($request));
        $error = null;

        set_error_handler(static function (int $severity, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $stream = fopen(trim($request->url), 'r', false, $context);
        } catch (ValueError $exception) {
            // An unusable URL, an empty one for instance, raises instead of
            // returning false.
            throw new ConnectionFailureException(
                'Unable to establish a connection: ' . $exception->getMessage(),
                0,
                $exception,
            );
        } finally {
            // The handler has to be restored on every path, otherwise it stays
            // installed and swallows the errors of the host application.
            restore_error_handler();
        }

        if ($stream === false) {
            throw new ConnectionFailureException('Unable to establish a connection: ' . ($error ?? 'unknown error'));
        }

        $metadata = stream_get_meta_data($stream);
        $body = stream_get_contents($stream);
        fclose($stream);

        /** @var list<string> $headerLines */
        $headerLines = $metadata['wrapper_data'] ?? [];

        $response = TransportResponse::fromRawHeaders(is_string($body) ? $body : '', $headerLines);

        $this->rejectTruncatedBody($response);

        return $response;
    }

    /**
     * The stream wrapper hands over whatever arrived before the connection was
     * closed, so a body shorter than its announced length has to be reported
     * instead of being parsed as if it were complete.
     *
     * @throws ConnectionFailureException
     */
    private function rejectTruncatedBody(TransportResponse $response): void
    {
        // These answers carry no body, whatever length they announce.
        if ($response->statusCode < 200 || $response->statusCode === 204 || $response->statusCode === 304) {
            return;
        }

        $announced = array_values(array_unique($response->headerValues('Content-Length')));

        // Lengths that contradict each other say nothing reliable about what
        // should have arrived. libcurl hands such a response over as it is, and
        // this transport is expected to behave like it.
        if (count($announced) !== 1 || !ctype_digit($announced[0])) {
            return;
        }

        $missing = (int) $announced[0] - strlen($response->body);

        if ($missing > 0) {
            throw new ConnectionFailureException(
                sprintf('The response ended with %d bytes missing', $missing),
            );
        }
    }

    /**
     * Stream context options for a request.
     *
     * @return array<string, array<string, mixed>>
     */
    public function buildContextOptions(TransportRequest $request): array
    {
        $options = [
            'http' => [
                'method' => 'POST',
                'protocol_version' => 1.1,
                // The stream wrapper has a single timeout covering the whole
                // transfer, so fall back to the connect timeout when no total
                // timeout was configured.
                'timeout' => $this->options->transferTimeout > 0
                    ? $this->options->transferTimeout
                    : $this->options->connectTimeout,
                // See CurlTransport::buildOptions() for why redirects are not followed.
                'follow_location' => 0,
                'max_redirects' => 1,
                'header' => implode("\r\n", $request->headerLines()),
                'content' => $request->body,
                // Without this, error responses are turned into a warning and an
                // empty stream, which hides the JSON-RPC error object they carry.
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => $this->options->verifySsl,
                'verify_peer_name' => $this->options->verifySsl,
            ],
        ];

        if ($this->options->caFile !== null) {
            $options['ssl']['cafile'] = $this->options->caFile;
        }

        if ($this->options->localCert !== null) {
            $options['ssl']['local_cert'] = $this->options->localCert;
        }

        /** @var array<string, array<string, mixed>> $merged */
        $merged = array_replace_recursive($options, $this->options->extraOptions);

        return $merged;
    }
}
