<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow;

use Koh\IndexNow\Exception\HostNotAllowed;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends submissions over any PSR-18 client.
 *
 * The host policy runs before anything touches the network, and it runs here,
 * not in the integrations: a submission cannot be recalled, so the one check
 * that must never be skipped lives where every path goes through.
 *
 * Timeouts belong to the PSR-18 client you pass in. Give it a short one — five
 * to ten seconds — and let the caller's queue retry; an engine that is slow
 * today must not stall the process that publishes content. Do not let it
 * follow redirects either: the body carries the key.
 */
final readonly class IndexNowClient
{
    public const USER_AGENT = 'koh-indexnow/1 (+https://github.com/ohartwig/indexnow)';

    private Endpoint $endpoint;

    private HostPolicy $hostPolicy;

    public function __construct(
        private ClientInterface $http,
        private RequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
        ?Endpoint $endpoint = null,
        ?HostPolicy $hostPolicy = null,
        private string $userAgent = self::USER_AGENT,
    ) {
        $this->endpoint = $endpoint ?? Endpoint::default();
        $this->hostPolicy = $hostPolicy ?? HostPolicy::default();
    }

    /**
     * Send one protocol-sized submission.
     *
     * @throws HostNotAllowed    when the host must not be announced — nothing has been sent
     * @throws \LengthException  when the submission is empty or larger than the protocol allows
     */
    public function submit(Submission $submission): Result
    {
        $this->hostPolicy->assertAllows($submission->host);

        if ($submission->isEmpty() || $submission->count() > Submission::MAX_URLS) {
            throw new \LengthException(sprintf(
                'A submission must carry 1 to %d URLs; use submitAll() for larger lists. Got %d.',
                Submission::MAX_URLS,
                $submission->count(),
            ), 1790000006);
        }

        $request = $this->requests->createRequest('POST', $this->endpoint->url)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('User-Agent', $this->userAgent)
            ->withBody($this->streams->createStream(json_encode(
                $submission->toPayload(),
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            )));

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            return Result::transportError($e, $submission);
        }

        return Result::fromResponse($response, $submission);
    }

    /**
     * Send a submission of any size in protocol-sized parts.
     *
     * Stops at the first part that does not succeed: an engine that throttles
     * or fails on part one will do the same on part two, and the remaining
     * URLs are better kept for the caller's next attempt. The returned list
     * holds one result per part actually sent; an empty submission sends
     * nothing and returns an empty list.
     *
     * @return list<Result>
     *
     * @throws HostNotAllowed
     */
    public function submitAll(Submission $submission): array
    {
        $this->hostPolicy->assertAllows($submission->host);

        $results = [];
        foreach ($submission->chunks() as $chunk) {
            $results[] = $result = $this->submit($chunk);
            if (!$result->isSuccess()) {
                break;
            }
        }

        return $results;
    }

    public function endpoint(): Endpoint
    {
        return $this->endpoint;
    }

    public function hostPolicy(): HostPolicy
    {
        return $this->hostPolicy;
    }
}
