<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow;

use Koh\IndexNow\Exception\InvalidUrl;

/**
 * A URL in the only shape the protocol accepts: absolute http(s), RFC 3986
 * characters only, no credentials, no fragment.
 *
 * Nothing is percent-encoded here on the caller's behalf. Encoding is the
 * router's job; guessing it here would turn a routing bug into a submission of
 * a URL that does not exist.
 */
final readonly class Url
{
    public const MAX_LENGTH = 2048;

    /** RFC 3986 unreserved, reserved and "%" — anything else is not a valid URI character. */
    private const RFC3986 = '/^[A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]+$/D';

    private function __construct(
        public string $url,
        public string $host,
        public string $path,
    ) {}

    /** @throws InvalidUrl */
    public static function parse(string $candidate): self
    {
        if ($candidate === '' || strlen($candidate) > self::MAX_LENGTH) {
            throw new InvalidUrl($candidate, sprintf('a URL must be 1 to %d characters long', self::MAX_LENGTH));
        }
        if (preg_match(self::RFC3986, $candidate) !== 1) {
            throw new InvalidUrl($candidate, 'it contains characters RFC 3986 does not allow unencoded');
        }
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $candidate) === 1) {
            throw new InvalidUrl($candidate, 'it contains a broken percent-encoding');
        }

        $parts = parse_url($candidate);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidUrl($candidate, 'it is not an absolute URL');
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            throw new InvalidUrl($candidate, 'only http and https URLs can be submitted');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidUrl($candidate, 'URLs with credentials are never submitted');
        }

        $host = HostName::normalize($parts['host']);
        $path = $parts['path'] ?? '';
        $path = $path === '' ? '/' : $path;

        $url = $scheme . '://' . $host
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . $path
            . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return new self($url, $host, $path);
    }
}
