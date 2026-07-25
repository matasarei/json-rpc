<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Doubles;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * A PSR-18 client that also implements the PSR-17 factories, like Symfony's
 * Psr18Client does. Records the request it was given and replays a canned response.
 */
final class RecordingPsr18Client implements ClientInterface, RequestFactoryInterface, StreamFactoryInterface
{
    public ?RequestInterface $lastRequest = null;

    private readonly Psr17Factory $factory;

    /**
     * @param array<string, string|list<string>> $responseHeaders
     */
    public function __construct(
        private readonly int $statusCode = 200,
        private readonly string $responseBody = '',
        private readonly array $responseHeaders = [],
    ) {
        $this->factory = new Psr17Factory();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;

        $response = $this->factory
            ->createResponse($this->statusCode)
            ->withBody($this->factory->createStream($this->responseBody));

        foreach ($this->responseHeaders as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    public function createRequest(string $method, $uri): RequestInterface
    {
        return $this->factory->createRequest($method, $uri);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return $this->factory->createStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return $this->factory->createStreamFromFile($filename, $mode);
    }

    /**
     * @param resource $resource
     */
    public function createStreamFromResource($resource): StreamInterface
    {
        return $this->factory->createStreamFromResource($resource);
    }

    public function createUri(string $uri = ''): UriInterface
    {
        return $this->factory->createUri($uri);
    }
}
