<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow;

use Koh\IndexNow\Exception\InvalidUrl;

/**
 * Sorts an untrusted, mixed list of URLs into per-host lists.
 *
 * An application that changes content on several hosts at once — a multi-site
 * CMS, a shop with one domain per channel — produces one list and needs one
 * submission per host. An invalid URL is set aside with its reason instead of
 * failing the batch: one broken slug must not hold back a thousand good ones.
 */
final class UrlCollection
{
    /** @var array<string, array<string, true>> */
    private array $byHost = [];

    /** @var array<string, string> URL => reason */
    private array $rejected = [];

    /** @param iterable<string> $urls */
    public static function of(iterable $urls): self
    {
        $collection = new self();
        foreach ($urls as $url) {
            $collection->add($url);
        }

        return $collection;
    }

    public function add(string $candidate): bool
    {
        try {
            $url = Url::parse($candidate);
        } catch (InvalidUrl $e) {
            $this->rejected[$candidate] = $e->reason;

            return false;
        }
        $this->byHost[$url->host][$url->url] = true;

        return true;
    }

    /** @return list<string> */
    public function hosts(): array
    {
        return array_map('strval', array_keys($this->byHost));
    }

    /** @return list<string> */
    public function urlsFor(string $host): array
    {
        return array_map('strval', array_keys($this->byHost[HostName::normalize($host)] ?? []));
    }

    /** @return array<string, string> URL => reason */
    public function rejected(): array
    {
        return $this->rejected;
    }

    public function isEmpty(): bool
    {
        return $this->byHost === [];
    }
}
