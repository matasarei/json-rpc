<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Transport;

use InvalidArgumentException;
use JsonRPC\Transport\CookieJar;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CookieJar::class)]
final class CookieJarTest extends TestCase
{
    public function testStartsEmptyOrPrefilled(): void
    {
        $this->assertTrue((new CookieJar())->isEmpty());
        $this->assertSame(['a' => '1'], (new CookieJar(['a' => '1']))->cookies);
    }

    public function testMergeKeepsExistingCookiesAndReplaceDropsThem(): void
    {
        $jar = new CookieJar(['a' => '1']);
        $jar->merge(['b' => '2']);
        $this->assertSame(['a' => '1', 'b' => '2'], $jar->cookies);

        $jar->replace(['c' => '3']);
        $this->assertSame(['c' => '3'], $jar->cookies);
    }

    public function testStoresOnlyTheCookiePairAndKeepsEqualSignsInValues(): void
    {
        $jar = new CookieJar();
        $jar->store([
            'session=abc=def; Path=/; HttpOnly; Expires=Wed, 21 Oct 2026 07:28:00 GMT',
            "theme=dark\r\n",
        ]);

        $this->assertSame(['session' => 'abc=def', 'theme' => 'dark'], $jar->cookies);
    }

    public function testForgetsACookieTheServerDeletes(): void
    {
        $jar = new CookieJar(['session' => 'abc', 'theme' => 'dark', 'keep' => 'me']);

        $jar->store(['session=; Path=/', 'theme=dark; Max-Age=0; Path=/']);

        $this->assertSame(['keep' => 'me'], $jar->cookies);
    }

    public function testKeepsACookieWithAFutureMaxAge(): void
    {
        $jar = new CookieJar();

        $jar->store(['session=abc; Max-Age=3600; Path=/']);

        $this->assertSame(['session' => 'abc'], $jar->cookies);
    }

    public function testForgetsACookieDeletedWithAPastExpires(): void
    {
        $jar = new CookieJar(['session' => 'live']);

        $jar->store(['session=deleted; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT']);

        $this->assertSame([], $jar->cookies);
    }

    public function testKeepsACookieWithAFutureExpires(): void
    {
        $jar = new CookieJar();

        $jar->store(['session=abc; Expires=Tue, 01 Jan 2999 00:00:00 GMT']);

        $this->assertSame(['session' => 'abc'], $jar->cookies);
    }

    public function testMaxAgeWinsOverExpires(): void
    {
        $jar = new CookieJar();

        $jar->store(['session=abc; Max-Age=3600; Expires=Thu, 01 Jan 1970 00:00:00 GMT']);

        $this->assertSame(['session' => 'abc'], $jar->cookies);
    }

    public function testIgnoresAnExpiresItCannotRead(): void
    {
        $jar = new CookieJar();

        $jar->store(['session=abc; Expires=whenever']);

        $this->assertSame(['session' => 'abc'], $jar->cookies);
    }

    public function testRefusesToStoreAValueTheServerCouldBreakAHeaderWith(): void
    {
        $jar = new CookieJar();

        $jar->store(["sid=ab\0cd", "other=one\r\nX-Injected: yes", "safe=value"]);

        $this->assertSame(['safe' => 'value'], $jar->cookies);
    }

    public function testRefusesCookiesTheApplicationCannotSend(): void
    {
        foreach ([['sid' => "ab\0cd"], ['sid' => 'a; injected=1'], ["bad\r\nname" => 'v']] as $cookies) {
            try {
                (new CookieJar())->merge($cookies);
                $this->fail('An exception should have been thrown');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('not allowed in a header', $exception->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);

        (new CookieJar())->replace(['sid' => "a\nb"]);
    }

    public function testIgnoresValuesWithoutAUsableCookiePair(): void
    {
        $jar = new CookieJar();
        $jar->store(['not-a-cookie', '=orphan-value', ' ; Path=/']);

        $this->assertTrue($jar->isEmpty());
    }

    public function testRendersTheCookieRequestHeader(): void
    {
        $jar = new CookieJar(['a' => '1', 'b' => '2']);

        $this->assertSame('a=1; b=2', $jar->headerValue());
    }

    public function testRendersNoHeaderWhenEmpty(): void
    {
        $this->assertNull((new CookieJar())->headerValue());
    }
}
