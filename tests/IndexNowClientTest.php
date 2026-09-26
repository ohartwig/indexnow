<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Tests;

use Koh\IndexNow\Endpoint;
use Koh\IndexNow\Exception\HostNotAllowed;
use Koh\IndexNow\Exception\InvalidEndpoint;
use Koh\IndexNow\IndexNowClient;
use Koh\IndexNow\Key;
use Koh\IndexNow\Outcome;
use Koh\IndexNow\Submission;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class IndexNowClientTest extends TestCase
{
    private Key $key;

    protected function setUp(): void
    {
        $this->key = Key::fromString('abcdef123456');
    }

    #[Test]
    public function aSubmissionIsPostedAsJsonToTheEndpoint(): void
    {
        $http = new RecordingClient(new Response(200));
        $result = $this->client($http)->submit(Submission::forHost('shop-domain.de', $this->key, ['https://shop-domain.de/a']));

        self::assertSame(Outcome::Accepted, $result->outcome);
        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.indexnow.org/indexnow', (string) $request->getUri());
        self::assertSame('application/json; charset=utf-8', $request->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('koh-indexnow/', $request->getHeaderLine('User-Agent'));
        self::assertSame(
            '{"host":"shop-domain.de","key":"abcdef123456","urlList":["https://shop-domain.de/a"]}',
            (string) $request->getBody(),
        );
    }

    #[Test]
    public function aForbiddenHostNeverReachesTheNetwork(): void
    {
        $http = new RecordingClient(new Response(200));

        try {
            $this->client($http)->submit(Submission::forHost('shop.dev.shop-domain.de', $this->key, ['https://shop.dev.shop-domain.de/']));
            self::fail('expected HostNotAllowed');
        } catch (HostNotAllowed) {
            self::assertSame([], $http->requests);
        }
    }

    /** @return iterable<string, array{int, Outcome, bool}> */
    public static function statusCodes(): iterable
    {
        yield '200' => [200, Outcome::Accepted, false];
        yield '202' => [202, Outcome::Pending, false];
        yield '400' => [400, Outcome::Rejected, false];
        yield '403 heals once the key file is reachable' => [403, Outcome::KeyNotVerified, true];
        yield '422' => [422, Outcome::Rejected, false];
        yield '429' => [429, Outcome::Throttled, true];
        yield '503' => [503, Outcome::ServerError, true];
        yield '301 is not followed' => [301, Outcome::Unexpected, false];
    }

    #[Test]
    #[DataProvider('statusCodes')]
    public function everyStatusMapsToAnOutcomeAQueueCanActOn(int $status, Outcome $expected, bool $retryable): void
    {
        $result = $this->client(new RecordingClient(new Response($status)))
            ->submit(Submission::forHost('shop-domain.de', $this->key, ['https://shop-domain.de/a']));

        self::assertSame($expected, $result->outcome);
        self::assertSame($retryable, $result->isRetryable());
        self::assertSame($status, $result->statusCode);
    }

    #[Test]
    public function retryAfterIsHonouredAndCapped(): void
    {
        $submission = Submission::forHost('shop-domain.de', $this->key, ['https://shop-domain.de/a']);

        self::assertSame(120, $this->client(new RecordingClient(new Response(429, ['Retry-After' => '120'])))->submit($submission)->retryAfterSeconds);
        self::assertSame(86400, $this->client(new RecordingClient(new Response(429, ['Retry-After' => '999999'])))->submit($submission)->retryAfterSeconds);
        self::assertNull($this->client(new RecordingClient(new Response(429, ['Retry-After' => 'soon'])))->submit($submission)->retryAfterSeconds);

        $inTenMinutes = gmdate('D, d M Y H:i:s', time() + 600) . ' GMT';
        $seconds = $this->client(new RecordingClient(new Response(503, ['Retry-After' => $inTenMinutes])))->submit($submission)->retryAfterSeconds;
        self::assertNotNull($seconds);
        self::assertEqualsWithDelta(600, $seconds, 5);
    }

    #[Test]
    public function aTransportFailureIsAResultNotAnException(): void
    {
        $http = new RecordingClient(new class ('connection reset while sending abcdef123456') extends \RuntimeException implements ClientExceptionInterface {});
        $result = $this->client($http)->submit(Submission::forHost('shop-domain.de', $this->key, ['https://shop-domain.de/a']));

        self::assertSame(Outcome::TransportError, $result->outcome);
        self::assertTrue($result->isRetryable());
        self::assertStringNotContainsString('abcdef123456', $result->detail);
    }

    #[Test]
    public function theKeyIsScrubbedFromEchoedResponseBodies(): void
    {
        $result = $this->client(new RecordingClient(new Response(422, [], '{"key":"abcdef123456","error":"mismatch"}')))
            ->submit(Submission::forHost('shop-domain.de', $this->key, ['https://shop-domain.de/a']));

        self::assertStringNotContainsString('abcdef123456', $result->detail);
        self::assertStringContainsString('mismatch', $result->detail);
    }

    #[Test]
    public function submitAllStopsAtTheFirstPartThatFails(): void
    {
        $urls = array_map(static fn(int $i): string => 'https://shop-domain.de/p/' . $i, range(1, 25000));
        $http = new RecordingClient(new Response(200), new Response(429), new Response(200));

        $results = $this->client($http)->submitAll(Submission::forHost('shop-domain.de', $this->key, $urls));

        self::assertCount(2, $results);
        self::assertSame(Outcome::Throttled, $results[1]->outcome);
        self::assertCount(2, $http->requests);
    }

    #[Test]
    public function anEmptySubmissionIsNotSent(): void
    {
        $http = new RecordingClient(new Response(200));

        self::assertSame([], $this->client($http)->submitAll(Submission::forHost('shop-domain.de', $this->key, [])));
        self::assertSame([], $http->requests);

        $this->expectException(\LengthException::class);
        $this->client($http)->submit(Submission::forHost('shop-domain.de', $this->key, []));
    }

    #[Test]
    public function endpointsAreHttpsAndKnownUnlessExplicitlyAllowed(): void
    {
        self::assertSame('https://www.bing.com/indexnow', Endpoint::fromUrl('https://www.bing.com/indexnow')->url);
        self::assertSame('https://relay.shop-domain.de/indexnow', Endpoint::fromUrl('https://relay.shop-domain.de', true)->url);

        foreach (['http://api.indexnow.org/indexnow', 'https://api.indexnow.org.evil.example/indexnow', 'https://api.indexnow.org/indexnow?key=x', 'https://u:p@api.indexnow.org/'] as $url) {
            try {
                Endpoint::fromUrl($url);
                self::fail('expected InvalidEndpoint for ' . $url);
            } catch (InvalidEndpoint) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function client(ClientInterface $http): IndexNowClient
    {
        $factory = new Psr17Factory();

        return new IndexNowClient($http, $factory, $factory);
    }
}

final class RecordingClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $replies;

    public function __construct(ResponseInterface|ClientExceptionInterface ...$replies)
    {
        $this->replies = array_values($replies);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $reply = array_shift($this->replies) ?? throw new \LogicException('no reply left');
        if ($reply instanceof ClientExceptionInterface) {
            throw $reply;
        }

        return $reply;
    }
}
