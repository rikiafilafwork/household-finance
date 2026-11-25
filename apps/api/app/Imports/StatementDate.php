<?php

namespace App\Imports;

use App\Imports\Exceptions\InvalidStatementValue;
use DateTimeImmutable;

/**
 * Tanggal di CSV bank → `YYYY-MM-DD` (tanggal lokal, Keputusan #12).
 *
 *  - Format dipilih dari daftar tetap (`FORMATS`), bukan format PHP bebas.
 *  - Nama bulan Indonesia (`Mei`, `Agu`/`Agt`, `Okt`, `Des`, …) diterima untuk format `M`.
 *  - Format tanpa tahun (`d/m`): tahun dari pemetaan kalau ada; kalau tidak, tahun tanggal acuan
 *    (biasanya tanggal upload), mundur setahun bila hasilnya jatuh setelah tanggal acuan
 *    (mutasi Desember yang diunggah Januari).
 */
final class StatementDate
{
    public const FORMATS = [
        'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'Y/m/d', 'd/m/y', 'd-m-y', 'm/d/Y',
        'd M Y', 'd-M-Y', 'd M y', 'd-M-y',
        'd/m', 'd-m', 'd M',
    ];

    private const MONTHS = [
        'jan' => 'Jan', 'januari' => 'Jan',
        'feb' => 'Feb', 'peb' => 'Feb', 'februari' => 'Feb', 'pebruari' => 'Feb',
        'mar' => 'Mar', 'maret' => 'Mar',
        'apr' => 'Apr', 'april' => 'Apr',
        'mei' => 'May', 'may' => 'May',
        'jun' => 'Jun', 'juni' => 'Jun',
        'jul' => 'Jul', 'juli' => 'Jul',
        'agu' => 'Aug', 'agt' => 'Aug', 'ags' => 'Aug', 'aug' => 'Aug', 'agustus' => 'Aug',
        'sep' => 'Sep', 'sept' => 'Sep', 'september' => 'Sep',
        'okt' => 'Oct', 'oct' => 'Oct', 'oktober' => 'Oct',
        'nov' => 'Nov', 'nop' => 'Nov', 'november' => 'Nov', 'nopember' => 'Nov',
        'des' => 'Dec', 'dec' => 'Dec', 'desember' => 'Dec',
    ];

    public static function hasYear(string $format): bool
    {
        return str_contains($format, 'Y') || str_contains($format, 'y');
    }

    /**
     * @throws InvalidStatementValue
     */
    public static function parse(string $value, string $format, DateTimeImmutable $reference, ?int $year = null): string
    {
        if (! in_array($format, self::FORMATS, true)) {
            throw new InvalidStatementValue("Format tanggal \"{$format}\" tidak didukung.");
        }

        $original = $value;
        $value = trim($value);

        if (str_contains($format, 'M')) {
            $value = preg_replace_callback(
                '/\p{L}+/u',
                fn (array $m) => self::MONTHS[mb_strtolower($m[0])] ?? $m[0],
                $value,
            ) ?? $value;
        }

        if (self::hasYear($format)) {
            return self::create($format, $value, $original);
        }

        $separator = str_contains($format, ' ') ? ' ' : $format[1];
        $withYear = fn (int $y) => self::tryCreate($format.$separator.'Y', $value.$separator.$y);

        if ($year !== null) {
            return $withYear($year)
                ?? throw new InvalidStatementValue("Tanggal \"{$original}\" tidak cocok dengan format {$format} (tahun {$year}).");
        }

        // Tahun acuan dulu; kalau jatuh setelah tanggal acuan (atau 29/02 di tahun bukan kabisat), tahun sebelumnya.
        $referenceYear = (int) $reference->format('Y');
        foreach ([$referenceYear, $referenceYear - 1] as $candidate) {
            $date = $withYear($candidate);
            if ($date !== null && $date <= $reference->format('Y-m-d')) {
                return $date;
            }
        }

        throw new InvalidStatementValue("Tanggal \"{$original}\" tidak cocok dengan format {$format}.");
    }

    private static function create(string $format, string $value, string $original): string
    {
        return self::tryCreate($format, $value)
            ?? throw new InvalidStatementValue("Tanggal \"{$original}\" tidak cocok dengan format {$format}.");
    }

    private static function tryCreate(string $format, string $value): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date->format('Y-m-d');
    }
}
