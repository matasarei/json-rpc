<?php

declare(strict_types=1);

namespace JsonRPC\Transport;

use InvalidArgumentException;
use JsonRPC\Exception\ConnectionFailureException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * Sends requests through any PSR-18 HTTP client.
 *
 * Requires psr/http-client and psr/http-factory, which are optional dependencies
 * of this library. Clients implementing the PSR-17 factories themselves (such as
 * Symfony's Psr18Client) can be passed on their own.
 */
final readonly class Psr18Transport implements TransportInterface
{
    private RequestFactoryInterface $requestFactory;

    private StreamFactoryInterface $streamFactory;

    /**
     * @throws InvalidArgumentException When a PSR-17 factory is missing
     */
    public function __construct(
        private ClientInterface $client,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $requestFactory ??= $client instanceof RequestFactoryInterface ? $client : null;
        $streamFactory ??= $client instanceof StreamFactoryInterface ? $client : null;

        if ($requestFactory === null) {
            throw new InvalidArgumentException(
                'A PSR-17 request factory is required, because the HTTP client does not implement one.',
            );
        }

        if ($streamFactory === null) {
            throw new InvalidArgumentException(
                'A PSR-17 stream factory is required, because the HTTP client does not implement one.',
            );
        }

        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $psrRequest = $this->requestFactory
            ->createRequest('POST', trim($request->url))
            ->withBody($this->streamFactory->createStream($request->body));

        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }

        try {
            $psrResponse = $this->client->sendRequest($psrRequest);

            // The body of a PSR-7 response is a lazy stream, so reading it is
            // still part of the request: a transfer cut short raises here.
            $body = (string) $psrResponse->getBody();
        } catch (ClientExceptionInterface | RuntimeException $exception) {
            throw new ConnectionFailureException(
                'Unable to establish a connection: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        $headers = [];

        foreach ($psrResponse->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = array_values($values);
        }

        return new TransportResponse($psrResponse->getStatusCode(), $body, $headers);
    }
}
