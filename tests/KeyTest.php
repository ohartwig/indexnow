<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Tests;

use Koh\IndexNow\Exception\InvalidKey;
use Koh\IndexNow\Key;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KeyTest extends TestCase
{
    private const SECRET = 'a-installation-secret-that-is-long-enough-0123456789';

    /** @return iterable<string, array{string}> */
    public static function malformedKeys(): iterable
    {
        yield 'too short' => ['abc1234'];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'underscore' => ['abcd_1234'];
        yield 'dot' => ['abcd.1234'];
        yield 'path traversal' => ['../../etc/passwd'];
        yield 'trailing newline' => ["abcd12345\n"];
    }

    #[Test]
    #[DataProvider('malformedKeys')]
    public function keysOutsideTheProtocolAlphabetAreRefused(string $key): void
    {
        $this->expectException(InvalidKey::class);
        Key::fromString($key);
    }

    #[Test]
    public function theRefusalDoesNotRepeatTheKey(): void
    {
        try {
            Key::fromString('secret_value_with_underscore');
            self::fail('expected InvalidKey');
        } catch (InvalidKey $e) {
            self::assertStringNotContainsString('secret_value', $e->getMessage());
        }
    }

    #[Test]
    public function boundaryLengthsAreAccepted(): void
    {
        self::assertSame('abcd-123', Key::fromString('abcd-123')->value);
        self::assertSame(128, strlen(Key::fromString(str_repeat('Z', 128))->value));
    }

    #[Test]
    public function aDerivedKeyIsStablePerHostAndDiffersBetweenHosts(): void
    {
        $first = Key::derive(self::SECRET, 'example.org');

        self::assertTrue($first->equals(Key::derive(self::SECRET, 'example.org')));
        self::assertFalse($first->equals(Key::derive(self::SECRET, 'shop.example.org')));
        self::assertFalse($first->equals(Key::derive(self::SECRET . 'x', 'example.org')));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first->value);
    }

    #[Test]
    public function derivationIgnoresHostSpelling(): void
    {
        self::assertTrue(Key::derive(self::SECRET, 'Example.ORG.')->equals(Key::derive(self::SECRET, 'example.org')));
    }

    #[Test]
    public function aShortSecretCannotProduceAGuessableKey(): void
    {
        $this->expectException(InvalidKey::class);
        Key::derive('too-short', 'example.org');
    }

    #[Test]
    public function theKeyFileIsMatchedExactlyBelowTheBasePath(): void
    {
        $key = Key::fromString('abcdef123456');

        self::assertTrue($key->isFilePath('/abcdef123456.txt'));
        self::assertTrue($key->isFilePath('/shop/abcdef123456.txt', '/shop/'));
        self::assertFalse($key->isFilePath('/ABCDEF123456.txt'));
        self::assertFalse($key->isFilePath('/abcdef123456.txt/'));
        self::assertFalse($key->isFilePath('/en/abcdef123456.txt'));
        self::assertFalse($key->isFilePath('/abcdef12345.txt'));
    }
}
