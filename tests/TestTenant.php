<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Dropshipping\Filament\Tests;

/**
 * The merchant the test panel is currently showing.
 *
 * A mutable holder rather than a constant, because the custody proof is about
 * two merchants and the interesting failures are the ones a single-merchant test
 * passes either way. Every custody proof here gives the second merchant a
 * **deliberately identical** supplier code, product reference, order reference
 * and destination reference: two merchants both buying from `acme` and both
 * calling an order `order-1` is the ordinary case, and a proof that only creates
 * one merchant's rows proves nothing about a `where` clause that was never
 * written.
 */
final class TestTenant
{
    public const PRIMARY = 'tenant-a';

    public const OTHER = 'tenant-b';

    private static string $current = self::PRIMARY;

    public static function current(): string
    {
        return self::$current;
    }

    public static function use(string $tenantId): void
    {
        self::$current = $tenantId;
    }

    public static function reset(): void
    {
        self::$current = self::PRIMARY;
    }
}
