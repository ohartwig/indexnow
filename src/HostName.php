<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow;

/**
 * One canonical spelling per host.
 *
 * The protocol compares the "host" field with the host of every submitted URL
 * and with the host serving the key file. Three spellings of the same name
 * ("Example.com", "example.com.", "xn--...") would otherwise produce three
 * keys, three payloads and a 422 from the search engine.
 */
final class HostName
{
    public static function normalize(string $host): string
    {
        $host = strtolower(trim($host));
        $host = rtrim($host, '.');

        if ($host !== '' && preg_match('/[^\x20-\x7E]/', $host) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }

        return $host;
    }
}
