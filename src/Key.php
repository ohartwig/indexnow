<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow;

use Koh\IndexNow\Exception\InvalidKey;

/**
 * An IndexNow key: proof that whoever submits URLs for a host controls it.
 *
 * The protocol allows 8 to 128 characters of a-z, A-Z, 0-9 and "-". The key is
 * not a secret in the cryptographic sense — it is published in the key file —
 * but until the first submission nobody else knows it, and a key that can be
 * guessed lets a stranger spend the host's crawl budget. Hence derive(): a key
 * computed from an installation secret needs no storage, no provisioning step
 * and no one to remember it, and it differs per host by construction.
 */
final readonly class Key implements \Stringable
{
    private const PATTERN = '/^[A-Za-z0-9-]{8,128}$/D';

    /** Versioned so a future change of the derivation cannot collide with today's keys. */
    private const DERIVATION_CONTEXT = 'koh-indexnow/v1|';

    private const MIN_SECRET_BYTES = 32;

    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidKey::malformed(strlen($value));
        }

        return new self($value);
    }

    /**
     * A stable, host-specific key from an installation secret (HMAC-SHA256, 64 hex characters).
     *
     * The same secret and host always give the same key, so every replica of an
     * application agrees on it without coordination. Rotating the secret rotates
     * the key; the search engines simply learn the new one on the next submission.
     */
    public static function derive(string $secret, string $host): self
    {
        if (strlen($secret) < self::MIN_SECRET_BYTES) {
            throw InvalidKey::weakSecret(strlen($secret));
        }

        return new self(hash_hmac('sha256', self::DERIVATION_CONTEXT . HostName::normalize($host), $secret));
    }

    /** "<key>.txt" — the file name the protocol expects at the key location. */
    public function fileName(): string
    {
        return $this->value . '.txt';
    }

    /**
     * Whether a request path asks for this key's file below $basePath.
     *
     * Compared in constant time: the answer to "is this the key?" must not leak
     * how many leading characters of a guess were right.
     */
    public function isFilePath(string $path, string $basePath = '/'): bool
    {
        return hash_equals($this->filePath($basePath), $path);
    }

    public function filePath(string $basePath = '/'): string
    {
        return rtrim($basePath, '/') . '/' . $this->fileName();
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
