<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow;

use Koh\IndexNow\Exception\HostNotAllowed;

/**
 * Decides whether a host may be announced to search engines at all.
 *
 * IndexNow submissions are shared between all participating engines and
 * cannot be taken back. A staging host that gets submitted once is known to
 * every one of them, so the check sits in the library, in front of any network
 * I/O, rather than in each integration where it can be forgotten.
 *
 * Refused by default:
 *  - IP literals and single-label names — nothing a search engine should index;
 *  - reserved and private-use suffixes (RFC 2606, RFC 6761, RFC 6762, RFC 8375,
 *    RFC 9476, ICANN's ".internal") such as .localhost, .test, .invalid;
 *  - pre-production labels such as "dev" or "staging" anywhere left of the
 *    registrable part, e.g. shop.dev.example.com or staging.example.co.uk.
 *
 * The label rule looks only at labels left of the last two, so real names like
 * dev.to or the ".dev" top-level domain are not caught by it.
 */
final readonly class HostPolicy
{
    public const RESERVED_SUFFIXES = [
        'localhost',
        'test',
        'invalid',
        'example',
        'local',
        'localdomain',
        'internal',
        'intranet',
        'lan',
        'home',
        'corp',
        'private',
        'home.arpa',
        'onion',
        'alt',
        'example.com',
        'example.net',
        'example.org',
    ];

    public const PRE_PRODUCTION_LABELS = [
        'dev',
        'develop',
        'development',
        'staging',
        'stage',
        'stg',
        'test',
        'testing',
        'qa',
        'uat',
        'preview',
        'sandbox',
    ];

    /**
     * @param list<string> $deniedSuffixes additional suffixes to refuse, matched on label boundaries
     * @param list<string> $preProductionLabels labels that mark a host as not production
     */
    public function __construct(
        private array $deniedSuffixes = [],
        private array $preProductionLabels = self::PRE_PRODUCTION_LABELS,
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public function withDeniedSuffixes(string ...$suffixes): self
    {
        $normalized = array_map(static fn(string $suffix): string => HostName::normalize(ltrim($suffix, '.*')), $suffixes);

        return new self(
            array_values(array_unique([...$this->deniedSuffixes, ...array_filter($normalized, static fn(string $s): bool => $s !== '')])),
            $this->preProductionLabels,
        );
    }

    /** @param list<string> $labels */
    public function withPreProductionLabels(array $labels): self
    {
        return new self($this->deniedSuffixes, array_map('strtolower', $labels));
    }

    public function allows(string $host): bool
    {
        return $this->refusalReason($host) === null;
    }

    /** @throws HostNotAllowed */
    public function assertAllows(string $host): void
    {
        $reason = $this->refusalReason($host);
        if ($reason !== null) {
            throw new HostNotAllowed($host, $reason);
        }
    }

    /** Null when the host may be submitted, otherwise why not — meant for status output and logs. */
    public function refusalReason(string $host): ?string
    {
        $host = HostName::normalize($host);

        if ($host === '') {
            return 'the host is empty';
        }
        if (str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return 'IP addresses are never submitted';
        }
        if (!str_contains($host, '.')) {
            return 'single-label hosts are not public';
        }
        if (strlen($host) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host) !== 1) {
            return 'not a valid DNS host name';
        }

        foreach ([...self::RESERVED_SUFFIXES, ...$this->deniedSuffixes] as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return sprintf('".%s" is a reserved or denied suffix', $suffix);
            }
        }

        $labels = explode('.', $host);
        foreach (array_slice($labels, 0, -2) as $label) {
            if (in_array($label, $this->preProductionLabels, true)) {
                return sprintf('the label "%s" marks a pre-production host', $label);
            }
        }

        return null;
    }
}
