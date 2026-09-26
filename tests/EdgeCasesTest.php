<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Tests;

use Koh\IndexNow\Endpoint;
use Koh\IndexNow\Exception\HostNotAllowed;
use Koh\IndexNow\Exception\InvalidEndpoint;
use Koh\IndexNow\Exception\InvalidKey;
use Koh\IndexNow\Exception\InvalidUrl;
use Koh\IndexNow\HostPolicy;
use Koh\IndexNow\IndexNowClient;
use Koh\IndexNow\Key;
use Koh\IndexNow\Outcome;
use Koh\IndexNow\Result;
use Koh\IndexNow\Submission;
use Koh\IndexNow\Url;
use Koh\IndexNow\UrlCollection;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The boundaries: where a value turns from accepted to refused, and what the
 * normalised form is. Found by mutation testing, kept because each of them is
 * a place where a plausible edit would change behaviour silently.
 */
final class EdgeCasesTest extends TestCase
{
    #[Test]
    public function anEndpointIsNormalisedNotJustAccepted(): void
    {
        self::assertSame('https://www.bing.com/indexnow', Endpoint::fromUrl("  HTTPS://WWW.Bing.com/indexnow \n")->url);
        self::assertSame('https://api.indexnow.org/indexnow', Endpoint::fromUrl('https://api.indexnow.org')->url);
        self::assertSame('https://relay.shop-domain.de:8443/submit', Endpoint::fromUrl('https://relay.shop-domain.de:8443/submit', true)->url);
        self::assertSame(Endpoint::DEFAULT_URL, (string) Endpoint::default());
    }

    /** @return iterable<string, array{string}> */
    public static function refusedEndpoints(): iterable
    {
        yield 'not a URL' => ['api.indexnow.org/indexnow'];
        yield 'user only' => ['https://user@api.indexnow.org/indexnow'];
        yield 'fragment' => ['https://api.indexnow.org/indexnow#x'];
    }

    #[Test]
    #[DataProvider('refusedEndpoints')]
    public function endpointsThatCouldLeakOrMisrouteTheKeyAreRefused(string $url): void
    {
        try {
            Endpoint::fromUrl($url);
            self::fail('expected InvalidEndpoint');
        } catch (InvalidEndpoint $e) {
            self::assertStringStartsWith('Invalid IndexNow endpoint: ', $e->getMessage());
        }
    }

    #[Test]
    public function aUrlIsNormalisedToOneSpelling(): void
    {
        self::assertSame('https://shop-domain.de/', Url::parse('HTTPS://Shop-Domain.DE')->url);
        self::assertSame('http://shop-domain.de:8080/a?b=1', Url::parse('http://shop-domain.de:8080/a?b=1#frag')->url);
        self::assertSame('/a', Url::parse('https://shop-domain.de/a')->path);
    }

    #[Test]
    public function theUrlLengthLimitIsInclusive(): void
    {
        $prefix = 'https://shop-domain.de/';
        $exact = $prefix . str_repeat('a', Url::MAX_LENGTH - strlen($prefix));
        self::assertSame($exact, Url::parse($exact)->url);

        $this->expectException(InvalidUrl::class);
        Url::parse($exact . 'a');
    }

    #[Test]
    public function anInvalidUrlIsPrintableInALogLine(): void
    {
        $error = new InvalidUrl("https://shop-domain.de/\r\n" . str_repeat('x', 300), 'because');

        self::assertStringNotContainsString("\r", $error->getMessage());
        self::assertStringContainsString('https://shop-domain.de/??', $error->getMessage());
        self::assertStringContainsString('…', $error->getMessage());
        self::assertStringEndsWith(': because.', $error->getMessage());
        self::assertStringNotContainsString(str_repeat('x', 200), $error->getMessage());
    }

    #[Test]
    public function refusalsNameWhatWasRefusedAndWhy(): void
    {
        $refusal = new HostNotAllowed('shop.test', 'reserved');
        self::assertSame('Host "shop.test" is not submitted to IndexNow: reserved.', $refusal->getMessage());

        self::assertStringContainsString('got 3 characters', InvalidKey::malformed(3)->getMessage());
        self::assertStringContainsString('at least 32 bytes; got 5', InvalidKey::weakSecret(5)->getMessage());

        $codes = [$refusal->getCode(), InvalidKey::malformed(1)->getCode(), InvalidKey::weakSecret(1)->getCode(), (new InvalidUrl('x', 'y'))->getCode(), InvalidEndpoint::because('z')->getCode()];
        self::assertSame($codes, array_unique($codes), 'every failure has its own code');
    }

    #[Test]
    public function aSecretOfExactly32BytesIsEnough(): void
    {
        self::assertSame(64, strlen(Key::derive(str_repeat('s', 32), 'shop-domain.de')->value));

        $this->expectException(InvalidKey::class);
        Key::derive(str_repeat('s', 31), 'shop-domain.de');
    }

    /** @return iterable<string, array{int, Outcome}> */
    public static function statusBoundaries(): iterable
    {
        yield '199' => [199, Outcome::Unexpected];
        yield '204' => [204, Outcome::Accepted];
        yield '299' => [299, Outcome::Accepted];
        yield '300' => [300, Outcome::Unexpected];
        yield '500' => [500, Outcome::ServerError];
        yield '599' => [599, Outcome::ServerError];
        yield '600' => [600, Outcome::Unexpected];
    }

    #[Test]
    #[DataProvider('statusBoundaries')]
    public function statusRangesEndWhereTheProtocolSays(int $status, Outcome $expected): void
    {
        self::assertSame($expected, Outcome::fromStatusCode($status));
    }

    #[Test]
    public function retryAfterIsNeverNegativeAndToleratesWhitespace(): void
    {
        $submission = Submission::forHost('shop-domain.de', Key::fromString('abcdef123456'), ['https://shop-domain.de/']);

        self::assertSame(0, Result::fromResponse(new Response(429, ['Retry-After' => 'Mon, 01 Jan 2024 00:00:00 GMT']), $submission)->retryAfterSeconds);
        self::assertSame(30, Result::fromResponse(new Response(429, ['Retry-After' => ' 30 ']), $submission)->retryAfterSeconds);
        self::assertSame(86400, Result::fromResponse(new Response(429, ['Retry-After' => '86400']), $submission)->retryAfterSeconds);
        self::assertSame(3600, Result::fromResponse(new Response(429, ['Retry-After' => gmdate('D, d M Y H:i:s', 1_700_003_600) . ' GMT']), $submission, 1_700_000_000)->retryAfterSeconds);
        self::assertNull(Result::fromResponse(new Response(429), $submission)->retryAfterSeconds);
    }

    #[Test]
    public function responseExcerptsAreOneShortLine(): void
    {
        $submission = Submission::forHost('shop-domain.de', Key::fromString('abcdef123456'), ['https://shop-domain.de/']);

        $long = Result::fromResponse(new Response(400, [], str_repeat('ä', 400)), $submission);
        self::assertSame(str_repeat('ä', 300) . '…', $long->detail);

        $exact = Result::fromResponse(new Response(400, [], str_repeat('b', 300)), $submission);
        self::assertSame(str_repeat('b', 300), $exact->detail);

        $messy = Result::fromResponse(new Response(400, [], "  line one\n\n\tline\x07 two  "), $submission);
        self::assertSame('line one line two', $messy->detail);

        $binary = Result::fromResponse(new Response(400, [], str_repeat("\xff", 400)), $submission);
        self::assertSame(303, strlen($binary->detail), 'invalid UTF-8 is cut by bytes');

        self::assertSame('shop-domain.de', $exact->host);
        self::assertSame(1, $exact->urlCount);
        self::assertFalse($exact->isSuccess());
        self::assertFalse($exact->isRetryable());
    }

    #[Test]
    public function chunkSizesAreClampedToWhatTheProtocolAllows(): void
    {
        $urls = array_map(static fn(int $i): string => 'https://shop-domain.de/' . $i, range(1, 3));
        $submission = Submission::forHost('shop-domain.de', Key::fromString('abcdef123456'), $urls);

        self::assertCount(3, $submission->chunks(1));
        self::assertCount(2, $submission->chunks(2));
        self::assertSame(2, $submission->chunks(2)[0]->count());
        self::assertFalse($submission->isEmpty());
    }

    #[Test]
    public function aKeyLocationAtTheRootScopesTheWholeHost(): void
    {
        $submission = Submission::forHost('shop-domain.de', Key::fromString('abcdef123456'), ['https://shop-domain.de/any/where'], 'https://shop-domain.de/abcdef123456.txt');

        self::assertSame(['https://shop-domain.de/any/where'], $submission->urls);
    }

    #[Test]
    public function aCollectionReportsEmptinessAndNormalisesHostLookups(): void
    {
        self::assertTrue(UrlCollection::of([])->isEmpty());
        self::assertTrue(UrlCollection::of(['nonsense'])->isEmpty());

        $collection = UrlCollection::of(['https://Shop-Domain.de/a']);
        self::assertFalse($collection->isEmpty());
        self::assertSame(['https://shop-domain.de/a'], $collection->urlsFor('SHOP-DOMAIN.DE.'));
        self::assertSame([], $collection->urlsFor('other.de'));
        self::assertTrue($collection->add('https://shop-domain.de/b'));
        self::assertFalse($collection->add('ftp://shop-domain.de/b'));
    }

    #[Test]
    public function theClientExposesItsEndpointPolicyAndUserAgent(): void
    {
        $factory = new Psr17Factory();
        $http = new RecordingClient(new Response(200));
        $policy = HostPolicy::default()->withDeniedSuffixes('shop-staging.eu');
        $client = new IndexNowClient($http, $factory, $factory, Endpoint::fromUrl('https://www.bing.com/indexnow'), $policy, 'custom-agent/1');

        self::assertSame('https://www.bing.com/indexnow', $client->endpoint()->url);
        self::assertSame($policy, $client->hostPolicy());

        $client->submit(Submission::forHost('shop-domain.de', Key::fromString('abcdef123456'), ['https://shop-domain.de/']));
        self::assertSame('custom-agent/1', $http->requests[0]->getHeaderLine('User-Agent'));
        self::assertSame('https://www.bing.com/indexnow', (string) $http->requests[0]->getUri());
    }

    #[Test]
    public function submitAllRefusesAForbiddenHostEvenForAnEmptyList(): void
    {
        $factory = new Psr17Factory();
        $this->expectException(HostNotAllowed::class);
        (new IndexNowClient(new RecordingClient(), $factory, $factory))->submitAll(Submission::forHost('shop.test', Key::fromString('abcdef123456'), []));
    }

    #[Test]
    public function internationalisedHostsAreSubmittedInTheirAsciiForm(): void
    {
        if (!function_exists('idn_to_ascii')) {
            self::markTestSkipped('ext-intl is not installed');
        }

        self::assertSame('xn--bcher-kva.de', \Koh\IndexNow\HostName::normalize(' Bücher.DE. '));
        self::assertTrue(Key::derive(str_repeat('s', 32), 'bücher.de')->equals(Key::derive(str_repeat('s', 32), 'xn--bcher-kva.de')));
        self::assertTrue(HostPolicy::default()->allows('bücher.de'));
    }

    #[Test]
    public function dnsLengthLimitsAreEnforcedAtTheirBoundaries(): void
    {
        $policy = HostPolicy::default();
        $label63 = str_repeat('a', 63);

        self::assertTrue($policy->allows($label63 . '.de'));
        self::assertFalse($policy->allows($label63 . 'a.de'), 'a label has at most 63 characters');
        self::assertFalse($policy->allows('-shop.de'), 'a label does not start with a hyphen');
        self::assertFalse($policy->allows('shop-.de'), 'a label does not end with a hyphen');
        self::assertFalse($policy->allows("shop.de\nevil.de"), 'a newline inside the name does not slip past the anchors');
        self::assertTrue($policy->allows("shop-domain.de\n"), 'surrounding whitespace from configuration files is trimmed');

        $name253 = implode('.', [$label63, $label63, $label63, str_repeat('b', 58)]) . '.de';
        self::assertSame(253, strlen($name253));
        self::assertTrue($policy->allows($name253));
        self::assertFalse($policy->allows('c' . $name253), 'a name has at most 253 characters');
        self::assertStringContainsString('".test"', (string) $policy->refusalReason('shop.test'));
    }
}
