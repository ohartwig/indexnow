<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Exception;

final class HostNotAllowed extends \DomainException implements IndexNowException
{
    public function __construct(public readonly string $host, public readonly string $reason)
    {
        parent::__construct(sprintf('Host "%s" is not submitted to IndexNow: %s.', $host, $reason), 1790000003);
    }
}
