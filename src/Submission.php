<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow;

use Koh\IndexNow\Exception\InvalidUrl;

/**
 * One host, one key, a list of URLs — exactly what the protocol's POST body carries.
 *
 * Everything the search engine would reject with a 422 is rejected here
 * instead, before a request is made: a URL on another host, a URL outside the
 * key file's directory, a scheme other than http(s). Construction is strict on
 * purpose; use UrlCollection to sort a mixed, untrusted list first.
 */
final readonly class Submission
{
    /** The protocol's limit per POST. */
    public const MAX_URLS = 10000;

    /** @param list<string> $urls */
    private function __construct(
        public string $host,
        public Key $key,
        public array $urls,
        public ?string $keyLocation,
    ) {}

    /**
     * @param iterable<string> $urls
     * @param string|null $keyLocation absolute URL of the key file when it is not "/<key>.txt";
     *                                 its directory then limits which URLs may be submitted
     *
     * @throws InvalidUrl
     */
    public static function forHost(string $host, Key $key, iterable $urls, ?string $keyLocation = null): self
    {
        $host = HostName::normalize($host);
        $scope = '/';

        if ($keyLocation !== null) {
            $location = Url::parse($keyLocation);
            if ($location->host !== $host) {
                throw new InvalidUrl($keyLocation, 'the key location must be on the submitted host');
            }
            if (!str_ends_with($location->path, '/' . $key->fileName())) {
                throw new InvalidUrl($keyLocation, 'the key location must point at "<key>.txt"');
            }
            $keyLocation = $location->url;
            $scope = substr($location->path, 0, (int) strrpos($location->path, '/') + 1);
        }

        $unique = [];
        foreach ($urls as $candidate) {
            $url = Url::parse($candidate);
            if ($url->host !== $host) {
                throw new InvalidUrl($candidate, sprintf('it is not on host "%s"', $host));
            }
            if (!str_starts_with($url->path, $scope)) {
                throw new InvalidUrl($candidate, sprintf('it is outside "%s", the directory of the key file', $scope));
            }
            $unique[$url->url] = true;
        }

        return new self($host, $key, array_keys($unique), $keyLocation);
    }

    public function isEmpty(): bool
    {
        return $this->urls === [];
    }

    public function count(): int
    {
        return count($this->urls);
    }

    /**
     * Split into protocol-sized parts. An empty submission yields no parts.
     *
     * @param int<1, 10000> $size
     *
     * @return list<self>
     */
    public function chunks(int $size = self::MAX_URLS): array
    {
        $size = max(1, min($size, self::MAX_URLS));

        return array_map(
            fn(array $urls): self => new self($this->host, $this->key, $urls, $this->keyLocation),
            array_chunk($this->urls, $size),
        );
    }

    /** @return array{host: string, key: string, keyLocation?: string, urlList: list<string>} */
    public function toPayload(): array
    {
        $payload = ['host' => $this->host, 'key' => $this->key->value];
        if ($this->keyLocation !== null) {
            $payload['keyLocation'] = $this->keyLocation;
        }
        $payload['urlList'] = $this->urls;

        return $payload;
    }
}
