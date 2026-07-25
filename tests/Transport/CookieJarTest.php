<?php

declare(strict_types=1);

namespace JsonRPC\Tests\Transport;

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
