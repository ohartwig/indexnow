<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow;

use Psr\Http\Message\ResponseInterface;

final readonly class Result
{
    /** Longest honoured Retry-After; a longer one is a misconfiguration, not advice. */
    public const MAX_RETRY_AFTER = 86400;

    private const DETAIL_LENGTH = 300;

    public function __construct(
        public Outcome $outcome,
        public string $host,
        public int $urlCount,
        public ?int $statusCode = null,
        public ?int $retryAfterSeconds = null,
        public string $detail = '',
    ) {}

    public static function fromResponse(ResponseInterface $response, Submission $submission, ?int $now = null): self
    {
        $status = $response->getStatusCode();

        return new self(
            Outcome::fromStatusCode($status),
            $submission->host,
            $submission->count(),
            $status,
            self::parseRetryAfter($response->getHeaderLine('Retry-After'), $now ?? time()),
            self::excerpt((string) $response->getBody(), $submission->key),
        );
    }

    public static function transportError(\Throwable $error, Submission $submission): self
    {
        return new self(
            Outcome::TransportError,
            $submission->host,
            $submission->count(),
            null,
            null,
            self::excerpt($error::class . ': ' . $error->getMessage(), $submission->key),
        );
    }

    public function isSuccess(): bool
    {
        return $this->outcome->isSuccess();
    }

    public function isRetryable(): bool
    {
        return $this->outcome->isRetryable();
    }

    private static function parseRetryAfter(string $header, int $now): ?int
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }
        if (ctype_digit($header)) {
            $seconds = (int) $header;
        } else {
            // IMF-fixdate (RFC 9110), always GMT. Parsed with an explicit zone
            // because the old DATE_RFC7231 constant silently ignores it.
            $date = \DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \\G\\M\\T', $header, new \DateTimeZone('UTC'));
            if ($date === false) {
                return null;
            }
            $seconds = $date->getTimestamp() - $now;
        }

        return max(0, min($seconds, self::MAX_RETRY_AFTER));
    }

    /**
     * A short, single-line, key-free excerpt for logs.
     *
     * An engine or a proxy may echo the request back; the key must not travel
     * from there into a log aggregator.
     */
    private static function excerpt(string $text, Key $key): string
    {
        $text = str_replace($key->value, '[key]', $text);
        $text = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text)));

        // Cut on a character boundary without depending on ext-mbstring; an
        // invalid UTF-8 body falls back to a byte cut.
        if (preg_match('/^.{0,' . self::DETAIL_LENGTH . '}/su', $text, $m) === 1) {
            return $m[0] === $text ? $text : $m[0] . '…';
        }

        return strlen($text) > self::DETAIL_LENGTH ? substr($text, 0, self::DETAIL_LENGTH) . '…' : $text;
    }
}
