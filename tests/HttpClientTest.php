<?php

namespace JsonRPC;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../vendor/autoload.php';

defined('CURLOPT_URL') || define('CURLOPT_URL', 10002);
defined('CURLOPT_RETURNTRANSFER') || define('CURLOPT_RETURNTRANSFER', 19913);
defined('CURLOPT_CONNECTTIMEOUT') || define('CURLOPT_CONNECTTIMEOUT', 78);
defined('CURLOPT_TIMEOUT') || define('CURLOPT_TIMEOUT', 13);
defined('CURLOPT_FOLLOWLOCATION') || define('CURLOPT_FOLLOWLOCATION', 52);
defined('CURLOPT_MAXREDIRS') || define('CURLOPT_MAXREDIRS', 68);
defined('CURLOPT_SSL_VERIFYPEER') || define('CURLOPT_SSL_VERIFYPEER', 64);
defined('CURLOPT_POST') || define('CURLOPT_POST', 47);
defined('CURLOPT_POSTFIELDS') || define('CURLOPT_POSTFIELDS', 10015);
defined('CURLOPT_HTTPHEADER') || define('CURLOPT_HTTPHEADER', 10023);
defined('CURLOPT_HEADERFUNCTION') || define('CURLOPT_HEADERFUNCTION', 20079);
defined('CURLOPT_CAINFO') || define('CURLOPT_CAINFO', 10065);
defined('CURLE_OPERATION_TIMEDOUT') || define('CURLE_OPERATION_TIMEDOUT', 28);

function extension_loaded($extension)
{
    return HttpClientTest::$functions->extension_loaded($extension);
}

function fopen($url, $mode, $use_include_path, $context)
{
    return HttpClientTest::$functions->fopen($url, $mode, $use_include_path, $context);
}

function stream_context_create(array $params)
{
    return HttpClientTest::$functions->stream_context_create($params);
}

function curl_init()
{
    return HttpClientTest::$functions->curl_init();
}

function curl_setopt_array($ch, array $params)
{
    HttpClientTest::$functions->curl_setopt_array($ch, $params);
}

function curl_setopt($ch, $option, $value)
{
    HttpClientTest::$functions->curl_setopt($ch, $option, $value);
}

function curl_exec($ch)
{
    return HttpClientTest::$functions->curl_exec($ch);
}

function curl_errno($ch)
{
    return HttpClientTest::$functions->curl_errno($ch);
}

function curl_getinfo($ch, $option)
{
    HttpClientTest::$functions->curl_getinfo($ch, $option);
}

class TestableHttpClient extends HttpClient
{
    public function parseCookiesFromHeaders(array $headers)
    {
        $this->parseCookies($headers);
    }
}

class HttpClientTest extends TestCase
{
    public static $functions;

    protected function setUp(): void
    {
        self::$functions = $this
            ->getMockBuilder('stdClass')
            ->addMethods([
                'extension_loaded', 'fopen', 'stream_context_create', 'curl_getinfo',
                'curl_init', 'curl_setopt_array', 'curl_setopt', 'curl_exec', 'curl_errno',
            ])
            ->getMock();
    }

    public function testWithServerError()
    {
        $this->expectException('\JsonRPC\Exception\ServerErrorException');

        $httpClient = new HttpClient();
        $httpClient->handleExceptions([
            'HTTP/1.0 301 Moved Permanently',
            'Connection: close',
            'HTTP/1.1 500 Internal Server Error',
                                      ]);
    }

    public function testWithConnectionFailure()
    {
        $this->expectException('\JsonRPC\Exception\ConnectionFailureException');

        $httpClient = new HttpClient();
        $httpClient->handleExceptions([
            'HTTP/1.1 404 Not Found',
                                      ]);
    }

    public function testWithAccessForbidden()
    {
        $this->expectException('\JsonRPC\Exception\AccessDeniedException');

        $httpClient = new HttpClient();
        $httpClient->handleExceptions([
            'HTTP/1.1 403 Forbidden',
                                      ]);
    }

    public function testWithAccessNotAllowed()
    {
        $this->expectException('\JsonRPC\Exception\AccessDeniedException');

        $httpClient = new HttpClient();
        $httpClient->handleExceptions([
            'HTTP/1.0 401 Unauthorized',
                                      ]);
    }

    public function testWithHttp2ServerError()
    {
        $this->expectException('\JsonRPC\Exception\ServerErrorException');

        $httpClient = new HttpClient();
        $httpClient->handleExceptions([
            'HTTP/2 500',
        ]);
    }

    public function testWithHttp2AccessForbidden()
    {
        $this->expectException('\JsonRPC\Exception\AccessDeniedException');

        $httpClient = new HttpClient();
        $httpClient->handleExceptions([
            'HTTP/2 403',
        ]);
    }

    public function testWithHttp2UnexpectedErrorWithoutReasonPhrase()
    {
        $this->expectException('\JsonRPC\Exception\ResponseException');

        $httpClient = new HttpClient();
        $httpClient->handleExceptions([
            'HTTP/2 429',
        ]);
    }

    public function testUnexpectedErrorIsIgnoredForJsonResponse()
    {
        $httpClient = new HttpClient();
        $httpClient->handleExceptions(['HTTP/2 429'], true);
        $httpClient->handleExceptions(['HTTP/1.1 429 Too Many Requests'], true);

        $this->addToAssertionCount(1);
    }

    public function testRedirectResponseIsReportedAsError()
    {
        $this->expectException('\JsonRPC\Exception\ResponseException');

        $httpClient = new HttpClient();
        $httpClient->handleExceptions([
            'HTTP/1.1 301 Moved Permanently',
            'Location: https://example.com/new',
        ]);
    }

    public function testParseCookiesIgnoresAttributesAndKeepsEqualSigns()
    {
        $httpClient = new TestableHttpClient();
        $httpClient->parseCookiesFromHeaders([
            'Set-Cookie: session=abc=def; Path=/; HttpOnly; Expires=Wed, 21 Oct 2026 07:28:00 GMT',
            "Set-Cookie: token=xyz\r\n",
        ]);

        $this->assertSame(['session' => 'abc=def', 'token' => 'xyz'], $httpClient->getCookies());
    }

    public function testWithCallback()
    {
        self::$functions
            ->expects(static::exactly(1))
            ->method('extension_loaded')
            ->with('curl')
            ->will($this->returnValue(false));

        self::$functions
            ->expects(static::exactly(1))
            ->method('stream_context_create')
            ->with([
                'http' => [
                    'method' => 'POST',
                    'protocol_version' => 1.1,
                    'timeout' => 5,
                    'follow_location' => 0,
                    'max_redirects' => 1,
                    'header' => implode("\r\n", [
                        'User-Agent: JSON-RPC PHP Client <https://github.com/fguillot/JsonRPC>',
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'Connection: close',
                        'Content-Length: 4',
                    ]),
                    'content' => 'test',
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ]
            ])
            ->will($this->returnValue('context'));

        self::$functions
            ->expects(static::exactly(1))
            ->method('fopen')
            ->with('url', 'r', false, 'context')
            ->will($this->returnValue(false));

        $httpClient = new HttpClient('url');
        $httpClient->withBeforeRequestCallback(function (HttpClient $client, $payload) {
            $client->withHeaders(['Content-Length: ' . strlen($payload)]);
        });

        $this->expectException('\JsonRPC\Exception\ConnectionFailureException');
        $httpClient->execute('test');
    }

    public function testWithCurl()
    {
        self::$functions
            ->expects(static::exactly(1))
            ->method('extension_loaded')
            ->with('curl')
            ->will($this->returnValue(true));

        self::$functions
            ->expects(static::exactly(1))
            ->method('curl_init')
            ->will($this->returnValue('curl'));

        self::$functions
            ->expects(static::exactly(1))
            ->method('curl_setopt_array')
            ->with('curl', [
                CURLOPT_URL => 'url',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => 'test',
                CURLOPT_HTTPHEADER => [
                    'User-Agent: JSON-RPC PHP Client <https://github.com/fguillot/JsonRPC>',
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Connection: close',
                    'Content-Length: 4',
                ],
                CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$headers) {
                    $headers[] = $header;
                    return strlen($header);
                }
            ]);

        self::$functions
            ->expects(static::exactly(1))
            ->method('curl_setopt')
            ->with('curl', CURLOPT_CAINFO, 'test.crt');

        self::$functions
            ->expects(static::exactly(1))
            ->method('curl_exec')
            ->with('curl')
            ->will($this->returnValue(false));

        $httpClient = new HttpClient('url');
        $httpClient
            ->withSslLocalCert('test.crt')
            ->withBeforeRequestCallback(function (HttpClient $client, $payload) {
                $client->withHeaders(['Content-Length: ' . strlen($payload)]);
            });


        $this->expectException('\JsonRPC\Exception\ConnectionFailureException');
        $httpClient->execute('test');
    }

    public function testWithCurlTimeout()
    {
        self::$functions
            ->expects(static::exactly(1))
            ->method('extension_loaded')
            ->with('curl')
            ->will($this->returnValue(true));

        self::$functions
            ->expects(static::exactly(1))
            ->method('curl_init')
            ->will($this->returnValue('curl'));

        self::$functions
            ->expects(static::exactly(1))
            ->method('curl_setopt_array')
            ->with('curl', static::callback(function (array $options) {
                return $options[CURLOPT_CONNECTTIMEOUT] === 5 && $options[CURLOPT_TIMEOUT] === 10;
            }));

        self::$functions
            ->expects(static::exactly(1))
            ->method('curl_exec')
            ->with('curl')
            ->will($this->returnValue(false));

        self::$functions
            ->expects(static::exactly(1))
            ->method('curl_errno')
            ->with('curl')
            ->will($this->returnValue(CURLE_OPERATION_TIMEDOUT));

        $httpClient = new HttpClient('url');
        $httpClient->withExecutionTimeout(10);

        $this->expectException('\JsonRPC\Exception\ConnectionFailureException');
        $this->expectExceptionMessage('Operation timed out');
        $httpClient->execute('test');
    }
}
