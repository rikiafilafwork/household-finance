<?php

namespace App\Plan;

use App\Enums\Frequency;
use InvalidArgumentException;

/**
 * Jadwal template (Keputusan #9), murni tanpa DB dan tanpa jam:
 *
 *  - Tanggal jatuh tempo dihitung PER BULAN dari `day_of_month`, dibatasi hari terakhir bulan itu.
 *    Tidak bergulir dari tanggal bulan sebelumnya, jadi tidak ada drift: tanggal 31 → 31 Jan,
 *    28 Feb (29 di tahun kabisat), 31 Mar — bukan 28 Mar.
 *  - Template bulanan muncul tiap bulan; tahunan hanya di `month_of_year`-nya.
 */
final class MonthlyDueDate
{
    /** `YYYY-MM-DD` jatuh tempo di bulan itu. */
    public static function in(PlanMonth $month, int $dayOfMonth): string
    {
        if ($dayOfMonth < 1 || $dayOfMonth > 31) {
            throw new InvalidArgumentException("Tanggal jatuh tempo harus 1–31, bukan {$dayOfMonth}.");
        }

        [$year, $mon] = array_map('intval', explode('-', $month->key()));
        $day = min($dayOfMonth, self::daysIn($year, $mon));

        return sprintf('%04d-%02d-%02d', $year, $mon, $day);
    }

    /** Apakah template dengan frekuensi ini menghasilkan item di bulan itu. */
    public static function occursIn(PlanMonth $month, Frequency $frequency, ?int $monthOfYear): bool
    {
        return match ($frequency) {
            Frequency::Monthly => true,
            Frequency::Yearly => $monthOfYear !== null && (int) substr($month->key(), 5, 2) === $monthOfYear,
            Frequency::Weekly => throw new InvalidArgumentException('Template rencana hanya bulanan atau tahunan.'),
        };
    }

    /** Jumlah hari dalam bulan (kalender Gregorian), aritmetika integer. */
    public static function daysIn(int $year, int $month): int
    {
        return match ($month) {
            2 => ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0 ? 29 : 28,
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }
}
