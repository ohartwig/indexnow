<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Tests;

use Koh\IndexNow\Exception\InvalidUrl;
use Koh\IndexNow\Key;
use Koh\IndexNow\Submission;
use Koh\IndexNow\UrlCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SubmissionTest extends TestCase
{
    private Key $key;

    protected function setUp(): void
    {
        $this->key = Key::fromString('abcdef123456');
    }

    #[Test]
    public function thePayloadHasTheProtocolShape(): void
    {
        $submission = Submission::forHost('Shop-Domain.de', $this->key, [
            'https://shop-domain.de/a',
            'https://SHOP-DOMAIN.de/b?x=1#section',
        ]);

        self::assertSame([
            'host' => 'shop-domain.de',
            'key' => 'abcdef123456',
            'urlList' => ['https://shop-domain.de/a', 'https://shop-domain.de/b?x=1'],
        ], $submission->toPayload());
    }

    #[Test]
    public function duplicatesAreSentOnce(): void
    {
        $submission = Submission::forHost('shop-domain.de', $this->key, [
            'https://shop-domain.de/a',
            'https://shop-domain.de/a#top',
            'https://shop-domain.de/a',
        ]);

        self::assertSame(1, $submission->count());
    }

    #[Test]
    public function httpAndHttpsMayBeMixed(): void
    {
        $submission = Submission::forHost('shop-domain.de', $this->key, ['http://shop-domain.de/', 'https://shop-domain.de/']);

        self::assertSame(2, $submission->count());
    }

    /** @return iterable<string, array{string}> */
    public static function urlsTheEngineWouldRefuse(): iterable
    {
        yield 'other host' => ['https://other-domain.de/a'];
        yield 'subdomain is another host' => ['https://www.shop-domain.de/a'];
        yield 'relative' => ['/a'];
        yield 'ftp' => ['ftp://shop-domain.de/a'];
        yield 'credentials' => ['https://user:pass@shop-domain.de/a'];
        yield 'unencoded space' => ['https://shop-domain.de/a b'];
        yield 'unencoded umlaut' => ['https://shop-domain.de/grüße'];
        yield 'broken percent-encoding' => ['https://shop-domain.de/%zz'];
        yield 'newline injection' => ["https://shop-domain.de/a\r\nX-Evil: 1"];
    }

    #[Test]
    #[DataProvider('urlsTheEngineWouldRefuse')]
    public function urlsTheEngineWouldRefuseAreRefusedBeforeSending(string $url): void
    {
        $this->expectException(InvalidUrl::class);
        Submission::forHost('shop-domain.de', $this->key, [$url]);
    }

    #[Test]
    public function aKeyLocationLimitsSubmissionsToItsDirectory(): void
    {
        $submission = Submission::forHost('shop-domain.de', $this->key, ['https://shop-domain.de/shop/item'], 'https://shop-domain.de/shop/abcdef123456.txt');
        self::assertSame('https://shop-domain.de/shop/abcdef123456.txt', $submission->toPayload()['keyLocation'] ?? null);

        $this->expectException(InvalidUrl::class);
        Submission::forHost('shop-domain.de', $this->key, ['https://shop-domain.de/imprint'], 'https://shop-domain.de/shop/abcdef123456.txt');
    }

    #[Test]
    public function aKeyLocationMustNameTheKeyFile(): void
    {
        $this->expectException(InvalidUrl::class);
        Submission::forHost('shop-domain.de', $this->key, [], 'https://shop-domain.de/other.txt');
    }

    #[Test]
    public function aKeyLocationOnAnotherHostIsRefused(): void
    {
        $this->expectException(InvalidUrl::class);
        Submission::forHost('shop-domain.de', $this->key, [], 'https://cdn.shop-domain.de/abcdef123456.txt');
    }

    #[Test]
    public function largeListsAreSplitAtTheProtocolLimit(): void
    {
        $urls = array_map(static fn(int $i): string => 'https://shop-domain.de/p/' . $i, range(1, 10001));
        $chunks = Submission::forHost('shop-domain.de', $this->key, $urls)->chunks();

        self::assertCount(2, $chunks);
        self::assertSame(10000, $chunks[0]->count());
        self::assertSame(1, $chunks[1]->count());
        self::assertSame([], Submission::forHost('shop-domain.de', $this->key, [])->chunks());
    }

    #[Test]
    public function aMixedListIsSortedByHostAndBadUrlsAreSetAside(): void
    {
        $collection = UrlCollection::of([
            'https://shop-domain.de/a',
            'https://shop-domain.ch/a',
            'https://SHOP-DOMAIN.de/b',
            'not a url',
        ]);

        self::assertSame(['shop-domain.de', 'shop-domain.ch'], $collection->hosts());
        self::assertSame(['https://shop-domain.de/a', 'https://shop-domain.de/b'], $collection->urlsFor('shop-domain.de'));
        self::assertArrayHasKey('not a url', $collection->rejected());
    }
}
