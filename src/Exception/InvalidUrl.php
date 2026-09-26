<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Exception;

final class InvalidUrl extends \InvalidArgumentException implements IndexNowException
{
    public function __construct(public readonly string $url, public readonly string $reason)
    {
        parent::__construct(sprintf('URL "%s" cannot be submitted: %s.', self::printable($url), $reason), 1790000004);
    }

    private static function printable(string $url): string
    {
        $url = preg_replace('/[\x00-\x1F\x7F]/', '?', $url) ?? '';

        return strlen($url) > 200 ? substr($url, 0, 200) . '…' : $url;
    }
}
