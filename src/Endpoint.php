<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow;

use Koh\IndexNow\Exception\InvalidEndpoint;

/**
 * Where submissions go.
 *
 * Participating engines share every submission with each other, so one
 * endpoint is enough; api.indexnow.org is the neutral one. The list of known
 * hosts is a guard, not a feature: an endpoint taken from configuration is an
 * address the application will send its key to, and a typo there should fail
 * at boot rather than post to whoever owns the misspelt domain.
 */
final readonly class Endpoint implements \Stringable
{
    public const DEFAULT_URL = 'https://api.indexnow.org/indexnow';

    public const KNOWN_HOSTS = [
        'api.indexnow.org',
        'www.bing.com',
        'search.seznam.cz',
        'searchadvisor.naver.com',
        'yandex.com',
        'indexnow.yep.com',
        'indexnow.amazonbot.amazon',
    ];

    private function __construct(public string $url) {}

    public static function default(): self
    {
        return new self(self::DEFAULT_URL);
    }

    /**
     * @param bool $allowUnknownHost for a self-hosted relay or a test double; the scheme stays https-only
     *
     * @throws InvalidEndpoint
     */
    public static function fromUrl(string $url, bool $allowUnknownHost = false): self
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw InvalidEndpoint::because('not an absolute URL');
        }
        if (strtolower($parts['scheme']) !== 'https') {
            throw InvalidEndpoint::because('only https is accepted — the request carries the key');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw InvalidEndpoint::because('credentials, query and fragment are not allowed');
        }

        $host = HostName::normalize($parts['host']);
        if (!$allowUnknownHost && !in_array($host, self::KNOWN_HOSTS, true)) {
            throw InvalidEndpoint::because(sprintf('"%s" is not a known IndexNow endpoint', $host));
        }

        $path = $parts['path'] ?? '/indexnow';

        return new self('https://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($path === '' ? '/indexnow' : $path));
    }

    public function __toString(): string
    {
        return $this->url;
    }
}
