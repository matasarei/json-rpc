<?php

declare(strict_types=1);

namespace JsonRPC\Transport;

use CurlHandle;
use JsonRPC\Exception\ConnectionFailureException;

/**
 * Default transport when the cURL extension is available.
 */
final class CurlTransport implements TransportInterface
{
    public function __construct(private readonly TransportOptions $options = new TransportOptions())
    {
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $handle = curl_init();
        $headerLines = [];

        $options = $this->buildOptions($request);
        $options[CURLOPT_HEADERFUNCTION] = static function (CurlHandle $curl, string $header) use (&$headerLines): int {
            $headerLines[] = rtrim($header, "\r\n");

            return strlen($header);
        };

        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);

        if ($body === false) {
            throw new ConnectionFailureException($this->errorMessage($handle));
        }

        return TransportResponse::fromRawHeaders(
            is_string($body) ? $body : '',
            $headerLines,
            curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        );
    }

    /**
     * cURL options for a request, except the header collector installed by send().
     *
     * @return array<int, mixed>
     */
    public function buildOptions(TransportRequest $request): array
    {
        $options = [
            CURLOPT_URL => trim($request->url),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request->body,
            CURLOPT_HTTPHEADER => $request->headerLines(),
            CURLOPT_CONNECTTIMEOUT => $this->options->connectTimeout,
            CURLOPT_TIMEOUT => $this->options->timeout,
            // A JSON-RPC endpoint is a fixed POST URL. Following a redirect would
            // resend the Authorization and Cookie headers to the new location,
            // which the server operator does not necessarily control.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => $this->options->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->options->verifySsl ? 2 : 0,
        ];

        if ($this->options->caFile !== null) {
            $options[CURLOPT_CAINFO] = $this->options->caFile;
        }

        if ($this->options->localCert !== null) {
            $options[CURLOPT_SSLCERT] = $this->options->localCert;
        }

        /** @var array<int, mixed> $merged */
        $merged = array_replace($options, $this->options->extraOptions);

        return $merged;
    }

    private function errorMessage(CurlHandle $handle): string
    {
        if (curl_errno($handle) === CURLE_OPERATION_TIMEDOUT) {
            return 'Operation timed out';
        }

        return sprintf(
            'Unable to establish a connection: %s (cURL error %d)',
            curl_error($handle),
            curl_errno($handle),
        );
    }
}
