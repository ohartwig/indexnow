<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Exception;

final class InvalidKey extends \InvalidArgumentException implements IndexNowException
{
    public static function malformed(int $length): self
    {
        // The key itself is deliberately not part of the message: it ends up
        // in logs, and a key is only worth anything while nobody else has it.
        return new self(sprintf(
            'An IndexNow key must be 8 to 128 characters of a-z, A-Z, 0-9 and "-"; got %d characters.',
            $length,
        ), 1790000001);
    }

    public static function weakSecret(int $length): self
    {
        return new self(sprintf(
            'Deriving an IndexNow key needs a secret of at least %d bytes; got %d.',
            32,
            $length,
        ), 1790000002);
    }
}
