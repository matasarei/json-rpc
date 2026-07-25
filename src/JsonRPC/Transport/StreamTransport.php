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

        return TransportResponse::fromRawHeaders(is_string($body) ? $body : '', $headerLines);
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
