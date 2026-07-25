<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Response;

use JsonRPC\Exception\InvalidJsonFormatException;
use JsonRPC\Exception\InvalidJsonRpcFormatException;
use JsonRPC\Exception\InvalidParamsException;
use JsonRPC\Exception\MethodNotFoundException;
use JsonRPC\Exception\ResponseException;
use JsonRPC\Response\ResponseParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseParser::class)]
final class ResponseParserTest extends TestCase
{
    private ResponseParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ResponseParser();
    }

    public function testReturnsTheResult(): void
    {
        $this->assertSame('foobar', $this->parser->parse(['jsonrpc' => '2.0', 'result' => 'foobar', 'id' => 1]));
    }

    public function testReturnsANullResult(): void
    {
        $this->assertNull($this->parser->parse(['jsonrpc' => '2.0', 'result' => null, 'id' => 1]));
    }

    public function testRejectsAnAnswerWithNeitherAResultNorAnError(): void
    {
        $this->expectException(InvalidJsonRpcFormatException::class);
        $this->expectExceptionMessage('neither a result nor an error member');

        $this->parser->parse(['status' => 'maintenance', 'retry_after' => 30]);
    }

    public function testRejectsAnAnswerCarryingAnotherRequestId(): void
    {
        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('The response does not answer the request with id 1');

        $this->parser->parse(['jsonrpc' => '2.0', 'result' => 'other', 'id' => 2], 1);
    }

    public function testAcceptsAnAnswerWhoseIdOnlyMatchesLoosely(): void
    {
        $this->assertSame('ok', $this->parser->parse(['jsonrpc' => '2.0', 'result' => 'ok', 'id' => '1'], 1));
        $this->assertSame('ok', $this->parser->parse(['jsonrpc' => '2.0', 'result' => 'ok', 'id' => 1.0], 1));
    }

    public function testAcceptsAnAnswerWithoutAnIdSuchAsAProtocolError(): void
    {
        $this->assertSame('ok', $this->parser->parse(['jsonrpc' => '2.0', 'result' => 'ok', 'id' => null], 1));
    }

    public function testRejectsAnAnswerWhoseIdIsNotUsable(): void
    {
        $this->expectException(ResponseException::class);

        $this->parser->parse(['jsonrpc' => '2.0', 'result' => 'ok', 'id' => ['nested']], 1);
    }

    public function testRejectsAnythingThatIsNotAnArray(): void
    {
        $this->expectException(InvalidJsonFormatException::class);
        $this->expectExceptionMessage('Malformed payload');

        $this->parser->parse(null);
    }

    public function testMapsParseErrors(): void
    {
        $this->expectException(InvalidJsonFormatException::class);
        $this->expectExceptionMessage('Parse error: broken');

        $this->parser->parse(['error' => ['code' => -32700, 'message' => 'broken']]);
    }

    public function testMapsInvalidRequests(): void
    {
        $this->expectException(InvalidJsonRpcFormatException::class);
        $this->expectExceptionMessage('Invalid Request: bad shape');

        $this->parser->parse(['error' => ['code' => -32600, 'message' => 'bad shape']]);
    }

    public function testMapsUnknownProcedures(): void
    {
        $this->expectException(MethodNotFoundException::class);
        $this->expectExceptionMessage('Procedure not found: Method not found');

        $this->parser->parse(['error' => ['code' => -32601, 'message' => 'Method not found']]);
    }

    public function testMapsInvalidParameters(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessage('Invalid arguments: Invalid params');

        $this->parser->parse(['error' => ['code' => -32602, 'message' => 'Invalid params']]);
    }

    public function testMapsAnyOtherErrorToAResponseExceptionCarryingItsData(): void
    {
        try {
            $this->parser->parse(['error' => ['code' => 42, 'message' => 'Custom', 'data' => ['field' => 'name']]]);
            $this->fail('An exception should have been thrown');
        } catch (ResponseException $exception) {
            $this->assertSame('Custom', $exception->getMessage());
            $this->assertSame(42, $exception->getCode());
            $this->assertSame(['field' => 'name'], $exception->getData());
        }
    }

    public function testToleratesErrorObjectsWithoutAMessage(): void
    {
        $this->expectException(ResponseException::class);

        $this->parser->parse(['error' => ['code' => 42]]);
    }

    public function testMatchesBatchAnswersByIdRegardlessOfTheirOrder(): void
    {
        $parsed = $this->parser->parseBatch(
            [
                ['jsonrpc' => '2.0', 'result' => 'second', 'id' => 2],
                ['jsonrpc' => '2.0', 'result' => 'first', 'id' => 1],
            ],
            [1, 2],
        );

        $this->assertSame([0 => 'first', 1 => 'second'], $parsed['results']);
        $this->assertSame([], $parsed['errors']);
    }

    public function testMatchesBatchAnswersWithStringIds(): void
    {
        $parsed = $this->parser->parseBatch([['jsonrpc' => '2.0', 'result' => 'ok', 'id' => 'a']], ['a']);

        $this->assertSame([0 => 'ok'], $parsed['results']);
    }

    public function testReportsFailedCallsOfABatchByPosition(): void
    {
        $parsed = $this->parser->parseBatch(
            [
                ['jsonrpc' => '2.0', 'result' => 'ok', 'id' => 1],
                ['jsonrpc' => '2.0', 'error' => ['code' => -32601, 'message' => 'Method not found'], 'id' => 2],
            ],
            [1, 2],
        );

        $this->assertSame([0 => 'ok'], $parsed['results']);
        $this->assertInstanceOf(MethodNotFoundException::class, $parsed['errors'][1]);
    }

    public function testReportsCallsTheServerNeverAnsweredFor(): void
    {
        $parsed = $this->parser->parseBatch([['jsonrpc' => '2.0', 'result' => 'ok', 'id' => 1]], [1, 2]);

        $this->assertSame([0 => 'ok'], $parsed['results']);
        $this->assertInstanceOf(ResponseException::class, $parsed['errors'][1]);
        $this->assertSame('No response received for the request with id 2', $parsed['errors'][1]->getMessage());
    }

    public function testIgnoresAnswersWithoutAUsableId(): void
    {
        $parsed = $this->parser->parseBatch(
            [
                ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Invalid Request'], 'id' => null],
                'garbage',
                ['jsonrpc' => '2.0', 'result' => 'ok', 'id' => 1],
            ],
            [1],
        );

        $this->assertSame([0 => 'ok'], $parsed['results']);
    }

    public function testReportsAnErrorTheServerRaisedForTheWholeBatch(): void
    {
        $this->expectException(InvalidJsonRpcFormatException::class);
        $this->expectExceptionMessage('Invalid Request: Invalid Request');

        $this->parser->parseBatch(
            ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Invalid Request'], 'id' => null],
            [1, 2],
        );
    }

    public function testRejectsABatchAnswerThatIsNeitherAListNorAnError(): void
    {
        $this->expectException(InvalidJsonFormatException::class);
        $this->expectExceptionMessage('Malformed payload');

        $this->parser->parseBatch(['jsonrpc' => '2.0', 'result' => 'not a batch', 'id' => 1], [1]);
    }

    public function testMatchesAnAnswerWhoseIdDecodedAsAFloat(): void
    {
        $parsed = $this->parser->parseBatch([['jsonrpc' => '2.0', 'result' => 'ok', 'id' => 1.0]], [1]);

        $this->assertSame([0 => 'ok'], $parsed['results']);
    }

    public function testDoesNotHandTheSameAnswerToTwoCallsWithLookAlikeIds(): void
    {
        $parsed = $this->parser->parseBatch(
            [
                ['jsonrpc' => '2.0', 'result' => 'from-int', 'id' => 1],
                ['jsonrpc' => '2.0', 'result' => 'from-string', 'id' => '1'],
            ],
            [1, '1'],
        );

        $this->assertSame([0 => 'from-int', 1 => 'from-string'], $parsed['results']);
    }

    public function testRejectsASingleAnswerThatIsABatch(): void
    {
        $this->expectException(InvalidJsonRpcFormatException::class);
        $this->expectExceptionMessage('Expected a single response but got a batch');

        $this->parser->parse([['jsonrpc' => '2.0', 'result' => 'the-answer', 'id' => 1]]);
    }

    public function testRejectsABatchAnswerThatIsNotAnArray(): void
    {
        $this->expectException(InvalidJsonFormatException::class);

        $this->parser->parseBatch(null, [1]);
    }
}
