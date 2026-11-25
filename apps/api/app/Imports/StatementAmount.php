<?php

namespace App\Imports;

use App\Exceptions\InvalidMoneyFormat;
use App\Imports\Exceptions\InvalidStatementValue;
use App\Support\Money;

/**
 * Nominal di CSV bank → integer rupiah ber-tanda, lewat `Money::parse` (tanpa float).
 *
 *  - Pemisah desimal per file: `,` (format Indonesia `1.250.000,00`) atau `.` (`1,250,000.00`).
 *    Untuk `.`, titik dan koma ditukar dulu lalu diparse sebagai format Indonesia.
 *  - Arah: suffix `DB`/`DR`/`D` = keluar, `CR`/`K` = masuk (mis. `1,250,000.00 DB`), tanda minus,
 *    atau tanda kurung `(25.000)`. Hanya satu penanda arah yang boleh dipakai.
 *  - Sen bukan nol ditolak (rupiah integer, Keputusan #1); baris itu jadi invalid, tidak dibulatkan.
 */
final class StatementAmount
{
    private const DEBIT = ['DB', 'DR', 'D', 'DEBIT', 'DEBET'];

    private const CREDIT = ['CR', 'K', 'C', 'KREDIT', 'CREDIT'];

    /**
     * @param  ','|'.'  $decimalSeparator
     *
     * @throws InvalidStatementValue
     */
    public static function parse(string $value, string $decimalSeparator = ','): int
    {
        $original = $value;
        $value = trim($value);
        if ($value === '') {
            throw new InvalidStatementValue('Nominal kosong.');
        }

        $direction = null;
        if (preg_match('/^(.*?[\d)])\s*([A-Za-z]+)\.?$/u', $value, $m)) {
            $direction = self::direction($m[2]);
            $value = trim($m[1]);
        }

        if (preg_match('/^\((.*)\)$/', $value, $m)) {
            if ($direction !== null) {
                throw new InvalidStatementValue("Nominal \"{$original}\" punya dua penanda arah.");
            }
            $direction = -1;
            $value = trim($m[1]);
        }

        if ($decimalSeparator === '.') {
            $value = strtr($value, [',' => '.', '.' => ',']);
        }

        try {
            $amount = Money::parse($value);
        } catch (InvalidMoneyFormat) {
            throw new InvalidStatementValue("Nominal \"{$original}\" tidak terbaca (pemisah desimal \"{$decimalSeparator}\"; sen bukan nol tidak didukung).");
        }

        if ($direction !== null) {
            if ($amount < 0) {
                throw new InvalidStatementValue("Nominal \"{$original}\" punya dua penanda arah.");
            }
            $amount *= $direction;
        }

        return $amount;
    }

    /**
     * Penanda arah (`DB`, `CR`, `Debet`, `K`, …) → -1 (keluar) atau 1 (masuk).
     *
     * @return -1|1
     *
     * @throws InvalidStatementValue
     */
    public static function direction(string $marker): int
    {
        $normalized = strtoupper(rtrim(trim($marker), '.'));

        return match (true) {
            in_array($normalized, self::DEBIT, true) => -1,
            in_array($normalized, self::CREDIT, true) => 1,
            default => throw new InvalidStatementValue("Penanda arah \"{$marker}\" tidak dikenal (pakai DB/CR)."),
        };
    }
}
