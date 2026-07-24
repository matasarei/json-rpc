<?php

namespace JsonRPC;

use Closure;
use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\Exception\ConnectionFailureException;
use JsonRPC\Exception\ResponseException;
use JsonRPC\Exception\ServerErrorException;
use JsonRPC\Logger\ErrorLogLogger;
use Psr\Log\LoggerInterface;

/**
 * Class HttpClient
 *
 * @package JsonRPC
 * @author  Frederic Guillot
 */
class HttpClient
{
    /**
     * URL of the server
     *
     * @var string
     */
    protected $url;

    /**
     * HTTP client timeout
     *
     * @var integer
     */
    protected $timeout = 5;

    /**
     * Default HTTP headers to send to the server
     *
     * @var array
     */
    protected $headers = [
        'User-Agent: JSON-RPC PHP Client <https://github.com/fguillot/JsonRPC>',
        'Content-Type: application/json',
        'Accept: application/json',
        'Connection: close',
    ];

    /**
     * Username for authentication
     *
     * @var string
     */
    protected $username;

    /**
     * Password for authentication
     *
     * @var string
     */
    protected $password;

    /**
     * PSR-3 logger for debug output
     *
     * @var LoggerInterface|null
     */
    protected $logger;

    /**
     * Cookies
     *
     * @var array
     */
    protected $cookies = [];

    /**
     * SSL certificates verification
     *
     * @var boolean
     */
    protected $verifySslCertificate = true;

    /**
     * SSL client certificate
     *
     * @var string
     */
    protected $sslLocalCert;

    /**
     * Callback called before the doing the request
     *
     * @var Closure
     */
    protected $beforeRequest;

    /**
     * CURL or stream meta data options
     *
     * @var array
     */
    protected $options = [];

    /**
     * HttpClient constructor
     *
     * @param string $url
     */
    public function __construct($url = '')
    {
        $this->url = $url;
    }

    /**
     * Set URL
     *
     * @param string $url
     *
     * @return $this
     */
    public function withUrl($url)
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Set username
     *
     * @param string $username
     *
     * @return $this
     */
    public function withUsername($username)
    {
        $this->username = $username;

        return $this;
    }

    /**
     * Set password
     *
     * @param string $password
     *
     * @return $this
     */
    public function withPassword($password)
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Set timeout
     *
     * @param integer $timeout
     *
     * @return $this
     */
    public function withTimeout($timeout)
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Set headers
     *
     * @param array $headers
     *
     * @return $this
     */
    public function withHeaders(array $headers)
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    /**
     * Set cookies
     *
     * @param array $cookies
     * @param boolean $replace
     */
    public function withCookies(array $cookies, $replace = false)
    {
        if ($replace) {
            $this->cookies = $cookies;
        } else {
            $this->cookies = array_merge($this->cookies, $cookies);
        }
    }

    /**
     * Set a PSR-3 logger to receive request/response debug messages
     *
     * @param LoggerInterface $logger
     *
     * @return $this
     */
    public function withLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Enable debug mode (logs to the PHP error log)
     *
     * @deprecated Use withLogger() with any PSR-3 logger instead
     *
     * @return $this
     */
    public function withDebug()
    {
        $this->logger = new ErrorLogLogger();

        return $this;
    }

    /**
     * Disable SSL verification
     *
     * @return $this
     */
    public function withoutSslVerification()
    {
        $this->verifySslCertificate = false;

        return $this;
    }

    /**
     * Assign a certificate to use TLS
     *
     * @return $this
     */
    public function withSslLocalCert($path)
    {
        $this->sslLocalCert = $path;

        return $this;
    }

    /**
     * Assign a callback before the request
     *
     * @param Closure $closure
     *
     * @return $this
     */
    public function withBeforeRequestCallback(Closure $closure)
    {
        $this->beforeRequest = $closure;

        return $this;
    }

    /**
     * Get cookies
     *
     * @return array
     */
    public function getCookies()
    {
        return $this->cookies;
    }

    /**
     * Do the HTTP request
     *
     * @param string $payload
     * @param string[] $headers Headers for this request
     *
     * @return array
     *
     * @throws AccessDeniedException
     * @throws ConnectionFailureException
     * @throws ResponseException
     * @throws ServerErrorException
     */
    public function execute($payload, array $headers = [])
    {
        if (is_callable($this->beforeRequest)) {
            call_user_func_array($this->beforeRequest, [$this, $payload, $headers]);
        }

        $requestHeaders = $this->buildHeaders($headers);

        if ($this->isCurlLoaded()) {
            $ch = curl_init();
            $headers = [];
            $options = [
                CURLOPT_URL => trim($this->url),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 2,
                CURLOPT_SSL_VERIFYPEER => $this->verifySslCertificate,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $requestHeaders,
                CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$headers) {
                    $headers[] = rtrim($header, "\r\n");

                    return strlen($header);
                }
            ];

            $options = array_replace_recursive($options, $this->options);

            if ($this->logger !== null) {
                $loggedOptions = $options;
                $loggedOptions[CURLOPT_HTTPHEADER] = $this->redactHeaders($requestHeaders);
                $this->logger->debug('CURL options', ['options' => $loggedOptions]);
            }

            curl_setopt_array($ch, $options);

            if ($this->sslLocalCert !== null) {
                curl_setopt($ch, CURLOPT_CAINFO, $this->sslLocalCert);
            }

            $response = curl_exec($ch);

            if (false === $response) {
                throw new ConnectionFailureException('Unable to establish a connection');
            }

            $response = json_decode($response, true);
        } else {
            $stream = fopen(trim($this->url), 'r', false, $this->buildContext($payload, $requestHeaders));

            if (!is_resource($stream)) {
                throw new ConnectionFailureException('Unable to establish a connection');
            }

            $metadata = stream_get_meta_data($stream);
            $headers = $metadata['wrapper_data'];
            $response = json_decode(stream_get_contents($stream), true);

            fclose($stream);
        }

        if ($this->logger !== null) {
            $this->logger->debug('Request', [
                'payload' => is_string($payload) ? $payload : json_encode($payload),
                'headers' => $this->redactHeaders($requestHeaders),
            ]);
            $this->logger->debug('Response', [
                'payload' => json_encode($response),
                'headers' => $headers,
            ]);
        }

        $this->handleExceptions($headers, is_array($response));
        $this->parseCookies($headers);

        return $response;
    }

    /**
     * Prepare stream context
     *
     * @param string $payload
     * @param string[] $headers
     *
     * @return resource
     */
    protected function buildContext($payload, array $headers = [])
    {
        $options = [
            'http' => [
                'method' => 'POST',
                'protocol_version' => 1.1,
                'timeout' => $this->timeout,
                'max_redirects' => 2,
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => $this->verifySslCertificate,
                'verify_peer_name' => $this->verifySslCertificate
            ]
        ];

        if ($this->sslLocalCert !== null) {
            $options['ssl']['local_cert'] = $this->sslLocalCert;
        }

        $options = array_replace_recursive($options, $this->options);

        return stream_context_create($options);
    }

    /**
     * Parse cookies from response
     *
     * @param array $headers
     */
    protected function parseCookies(array $headers)
    {
        foreach ($headers as $header) {
            $pos = stripos($header, 'Set-Cookie:');

            if ($pos !== false) {
                // Only the first name=value pair is the cookie itself,
                // the rest are attributes (Path, Expires, Secure, ...)
                $cookie = explode(';', substr($header, $pos + 11))[0];
                $item = explode('=', $cookie, 2);

                if (count($item) === 2) {
                    $name = trim($item[0]);
                    $value = trim($item[1], " \t\r\n");
                    $this->cookies[$name] = $value;
                }
            }
        }
    }

    /**
     * Throw an exception according the HTTP response
     *
     * @param array $headers
     * @param bool $isJsonResponse
     *
     * @throws AccessDeniedException
     * @throws ConnectionFailureException
     * @throws ServerErrorException
     * @throws ResponseException
     */
    public function handleExceptions(array $headers, $isJsonResponse = false)
    {
        $exceptions = [
            401 => '\JsonRPC\Exception\AccessDeniedException',
            403 => '\JsonRPC\Exception\AccessDeniedException',
            404 => '\JsonRPC\Exception\ConnectionFailureException',
            500 => '\JsonRPC\Exception\ServerErrorException'
        ];

        $errors = [];

        foreach ($headers as $header) {
            // Matches HTTP/1.0, HTTP/1.1 and HTTP/2 status lines, with or without a reason phrase
            if (preg_match('~^HTTP/\d+(?:\.\d+)?\s+(\d{3})~', $header, $matches)) {
                $statusCode = (int) $matches[1];

                if (isset($exceptions[$statusCode])) {
                    throw new $exceptions[$statusCode]('Response: ' . $header);
                }

                if ($statusCode >= 400 && $statusCode < 600) {
                    $errors[] = $header;
                }
            }
        }

        if ($isJsonResponse) {
            return;
        }

        if (!empty($errors)) {
            throw new ResponseException(sprintf('Unexpected response: %s', current($errors)));
        }
    }

    /**
     * @param int $name
     * @param mixed $value
     */
    public function addOption($name, $value)
    {
        $this->options[$name] = $value;
    }

    /**
     * Set the CURL or stream meta data options
     *
     * @param array $options
     */
    public function setOptions(array $options)
    {
        if (is_array($options)) {
            $this->options = $options;
        } else {
            $this->options = [];
        }
    }

    /**
     * Tests if the curl extension is loaded
     *
     * @return bool
     */
    protected function isCurlLoaded()
    {
        return extension_loaded('curl');
    }

    /**
     * Replace values of sensitive headers before logging
     *
     * @param string[] $headers
     *
     * @return string[]
     */
    protected function redactHeaders(array $headers)
    {
        return array_map(function ($header) {
            if (preg_match('/^(Authorization|Cookie|Proxy-Authorization)\s*:/i', $header, $matches)) {
                return $matches[1] . ': [redacted]';
            }

            return $header;
        }, $headers);
    }

    /**
     * Prepare Headers
     *
     * @param array $headers
     *
     * @return array
     */
    protected function buildHeaders(array $headers)
    {
        $headers = array_merge($this->headers, $headers);

        if (!empty($this->username) && !empty($this->password)) {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password);
        }

        if (!empty($this->cookies)) {
            $cookies = [];

            foreach ($this->cookies as $key => $value) {
                $cookies[] = $key . '=' . $value;
            }

            $headers[] = 'Cookie: ' . implode('; ', $cookies);
        }

        return $headers;
    }
}
