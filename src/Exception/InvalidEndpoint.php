<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Exception;

final class InvalidEndpoint extends \InvalidArgumentException implements IndexNowException
{
    public static function because(string $reason): self
    {
        return new self('Invalid IndexNow endpoint: ' . $reason . '.', 1790000005);
    }
}
