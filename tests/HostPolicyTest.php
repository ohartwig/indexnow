<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Tests;

use Koh\IndexNow\Exception\HostNotAllowed;
use Koh\IndexNow\HostPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HostPolicyTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function nonProductionHosts(): iterable
    {
        yield 'localhost' => ['localhost'];
        yield 'dev host under .localhost' => ['cms.dev.example.localhost'];
        yield '.test (RFC 6761)' => ['shop.test'];
        yield '.invalid, how inactive sites are parked' => ['site.invalid'];
        yield '.internal' => ['app.internal'];
        yield '.local (mDNS)' => ['printer.local'];
        yield 'home.arpa' => ['nas.home.arpa'];
        yield 'example.com' => ['www.example.com'];
        yield 'IPv4' => ['192.0.2.10'];
        yield 'IPv6 literal' => ['[2001:db8::1]'];
        yield 'single label' => ['intranet'];
        yield 'dev label' => ['budde.dev.shop-domain.eu'];
        yield 'staging label' => ['staging.shop-domain.co.uk'];
        yield 'preview label' => ['pr-12.preview.shop-domain.de'];
        yield 'not a DNS name' => ['under_score.shop-domain.de'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('nonProductionHosts')]
    public function nonProductionHostsAreNeverSubmitted(string $host): void
    {
        self::assertFalse(HostPolicy::default()->allows($host), $host);
    }

    /** @return iterable<string, array{string}> */
    public static function productionHosts(): iterable
    {
        yield 'apex' => ['ole-hartwig.eu'];
        yield 'www' => ['www.shop-domain.de'];
        yield 'two-label name that is a label word' => ['dev.to'];
        yield 'the real .dev TLD' => ['web.dev'];
        yield 'uppercase and trailing dot' => ['WWW.Shop-Domain.DE.'];
        yield 'label word inside a label' => ['developer.shop-domain.de'];
    }

    #[Test]
    #[DataProvider('productionHosts')]
    public function productionHostsAreAllowed(string $host): void
    {
        self::assertNull(HostPolicy::default()->refusalReason($host));
    }

    #[Test]
    public function additionalSuffixesCanBeDenied(): void
    {
        $policy = HostPolicy::default()->withDeniedSuffixes('*.customer-staging.eu', '.intern.de');

        self::assertFalse($policy->allows('a.customer-staging.eu'));
        self::assertFalse($policy->allows('x.intern.de'));
        self::assertTrue($policy->allows('customer-staging-live.eu'));
    }

    #[Test]
    public function preProductionLabelsCanBeReplaced(): void
    {
        $policy = HostPolicy::default()->withPreProductionLabels(['acceptance']);

        self::assertFalse($policy->allows('acceptance.shop-domain.de'));
        self::assertTrue($policy->allows('dev.shop-domain.de'));
    }

    #[Test]
    public function assertAllowsExplainsTheRefusal(): void
    {
        try {
            HostPolicy::default()->assertAllows('shop.dev.shop-domain.de');
            self::fail('expected HostNotAllowed');
        } catch (HostNotAllowed $e) {
            self::assertSame('shop.dev.shop-domain.de', $e->host);
            self::assertStringContainsString('"dev"', $e->reason);
        }
    }
}
