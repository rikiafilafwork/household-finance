<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Imports\ColumnMapping;
use App\Imports\Parsers\GenericCsvParser;
use App\Imports\StatementParser;
use DateTimeImmutable;

/**
 * Format file mutasi (`imports.bank_format`). `generic` butuh pemetaan kolom; parser khusus bank
 * (#52) ditambahkan sebagai case baru.
 */
enum StatementFormat: string
{
    use HasValues;

    case Generic = 'generic';

    public function needsColumnMapping(): bool
    {
        return match ($this) {
            self::Generic => true,
        };
    }

    /**
     * @param  DateTimeImmutable  $reference  tanggal upload (lokal user), acuan tahun untuk tanggal tanpa tahun
     */
    public function parser(?ColumnMapping $mapping, DateTimeImmutable $reference): StatementParser
    {
        return match ($this) {
            self::Generic => new GenericCsvParser(
                $mapping ?? throw new \LogicException('Format generic butuh pemetaan kolom.'),
                $reference,
            ),
        };
    }
}
