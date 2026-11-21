<?php

namespace App\Support;

use App\Exceptions\InvalidMoneyFormat;

/**
 * Satu-satunya pintu untuk uang (Architecture.md Keputusan #1).
 *
 * - Uang = integer rupiah (BIGINT), IDR tidak memakai sen.
 * - Format Indonesia: titik = pemisah ribuan, koma = desimal. "1.250" = 1250, bukan 1,25.
 * - Parsing berbasis string; tidak pernah memakai float.
 */
final class Money
{
    private const GROUPED = '/^\d{1,3}(?:\.\d{3})+$/';

    private const PLAIN = '/^\d+$/';

    /**
     * "Rp1.250.000" | "1.250.000,00" | "-Rp 50.000" | "1250000" → int rupiah.
     *
     * @throws InvalidMoneyFormat
     */
    public static function parse(string|int $input): int
    {
        if (is_int($input)) {
            return $input;
        }

        $original = $input;
        $value = preg_replace('/\s+/u', '', $input) ?? '';

        $negative = false;
        if (str_starts_with($value, '-')) {
            $negative = true;
            $value = substr($value, 1);
        }

        $value = preg_replace('/^rp\.?/i', '', $value) ?? '';

        if (str_starts_with($value, '-')) {
            if ($negative) {
                throw InvalidMoneyFormat::for($original, 'tanda minus ganda');
            }
            $negative = true;
            $value = substr($value, 1);
        }

        if ($value === '') {
            throw InvalidMoneyFormat::for($original, 'kosong');
        }

        $parts = explode(',', $value);
        if (count($parts) > 2) {
            throw InvalidMoneyFormat::for($original, 'koma lebih dari satu');
        }

        [$whole, $fraction] = [$parts[0], $parts[1] ?? null];

        if ($fraction !== null) {
            if (! preg_match('/^\d{1,2}$/', $fraction)) {
                throw InvalidMoneyFormat::for($original, 'bagian desimal tidak valid');
            }
            if ((int) $fraction !== 0) {
                throw InvalidMoneyFormat::for($original, 'rupiah tidak memiliki sen');
            }
        }

        if (preg_match(self::GROUPED, $whole)) {
            $digits = str_replace('.', '', $whole);
        } elseif (preg_match(self::PLAIN, $whole)) {
            $digits = $whole;
        } else {
            throw InvalidMoneyFormat::for($original, 'pemisah ribuan harus titik per 3 digit');
        }

        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return 0;
        }

        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw InvalidMoneyFormat::for($original, 'nominal terlalu besar');
        }

        $amount = (int) $digits;

        return $negative ? -$amount : $amount;
    }

    /**
     * 1250000 → "Rp1.250.000", -50000 → "-Rp50.000".
     */
    public static function format(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $digits = ltrim((string) $amount, '-');
        $grouped = strrev(implode('.', str_split(strrev($digits), 3)));

        return $sign.'Rp'.$grouped;
    }

    /**
     * Persen bulat ke bawah dengan aritmetika integer: percentOf(80_000, 100_000) → 80.
     */
    public static function percentOf(int $part, int $whole): int
    {
        if ($whole <= 0) {
            throw new \InvalidArgumentException('Pembagi persen harus lebih dari 0.');
        }

        return intdiv($part * 100, $whole);
    }

    /**
     * Perbandingan ambang tanpa pembagian: spent * 100 >= limit * threshold.
     */
    public static function reachesPercent(int $part, int $whole, int $threshold): bool
    {
        return $part * 100 >= $whole * $threshold;
    }
}
