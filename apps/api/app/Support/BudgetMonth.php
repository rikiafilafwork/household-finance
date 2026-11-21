<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Bulan budget = tanggal 1 (Keputusan #8). Di URL ditulis `YYYY-MM`.
 */
final class BudgetMonth
{
    public const ROUTE_PATTERN = '\d{4}-(0[1-9]|1[0-2])';

    public static function parse(string $yearMonth): CarbonImmutable
    {
        if (preg_match('/^'.self::ROUTE_PATTERN.'$/', $yearMonth) !== 1) {
            throw new InvalidArgumentException("Bulan tidak valid: {$yearMonth} (format YYYY-MM).");
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', "{$yearMonth}-01");
    }

    /** Tanggal 1 bulan dari sebuah tanggal transaksi (`occurred_on`). */
    public static function of(\DateTimeInterface|string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date)->startOfMonth()->startOfDay();
    }
}
