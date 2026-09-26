<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow;

/**
 * What became of a submission, in the terms a retry queue needs.
 *
 * The split that matters is retryable versus not. A 403 counts as retryable:
 * the usual cause is a key file the engine could not fetch yet — a cold cache,
 * a deploy in progress, a first submission racing the DNS — and it heals by
 * itself. A 400 or 422 does not: the same body will be refused again.
 */
enum Outcome: string
{
    /** 200 — URLs accepted. */
    case Accepted = 'accepted';
    /** 202 — URLs received, the key is still being validated. */
    case Pending = 'pending';
    /** 400 / 422 — malformed request or URLs that do not belong to the host. */
    case Rejected = 'rejected';
    /** 403 — the engine could not confirm the key file. */
    case KeyNotVerified = 'key-not-verified';
    /** 429 — too many requests. */
    case Throttled = 'throttled';
    /** 5xx — the engine failed. */
    case ServerError = 'server-error';
    /** No HTTP response at all: DNS, TLS, timeout, connection reset. */
    case TransportError = 'transport-error';
    /** Any status the protocol does not define. */
    case Unexpected = 'unexpected';

    public static function fromStatusCode(int $status): self
    {
        return match (true) {
            $status === 202 => self::Pending,
            $status >= 200 && $status < 300 => self::Accepted,
            $status === 400, $status === 422 => self::Rejected,
            $status === 403 => self::KeyNotVerified,
            $status === 429 => self::Throttled,
            $status >= 500 && $status < 600 => self::ServerError,
            default => self::Unexpected,
        };
    }

    public function isSuccess(): bool
    {
        return $this === self::Accepted || $this === self::Pending;
    }

    public function isRetryable(): bool
    {
        return match ($this) {
            self::KeyNotVerified, self::Throttled, self::ServerError, self::TransportError => true,
            default => false,
        };
    }
}
