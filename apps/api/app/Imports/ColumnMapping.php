<?php

namespace App\Imports;

use App\Imports\Csv\CsvReader;
use App\Imports\Exceptions\InvalidColumnMapping;

/**
 * Pemetaan kolom untuk `GenericCsvParser`, disimpan apa adanya di `imports.column_mapping` supaya bisa
 * dipakai ulang (preset) untuk file berikutnya dari akun yang sama.
 *
 * Kolom dirujuk dengan nama header (butuh `has_header`) atau indeks mulai 0. Nominal memakai salah satu:
 *  - `amount`: satu kolom ber-tanda / bersuffix DB-CR, opsional `direction` (kolom berisi DB/CR);
 *  - `debit` + `credit`: dua kolom, tepat satu yang terisi per baris.
 *
 * Contoh:
 *     ['has_header' => true, 'date' => 'Tanggal', 'date_format' => 'd/m/Y',
 *      'description' => ['Keterangan', 'Cabang'], 'debit' => 'Debet', 'credit' => 'Kredit',
 *      'decimal_separator' => ',']
 */
final readonly class ColumnMapping
{
    /**
     * @param  list<int|string>  $description
     * @param  ','|'.'  $decimalSeparator
     */
    private function __construct(
        public bool $hasHeader,
        public int $skipRows,
        public ?string $delimiter,
        public int|string $date,
        public string $dateFormat,
        public ?int $year,
        public array $description,
        public int|string|null $amount,
        public int|string|null $direction,
        public int|string|null $debit,
        public int|string|null $credit,
        public string $decimalSeparator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidColumnMapping
     */
    public static function fromArray(array $data): self
    {
        $hasHeader = (bool) ($data['has_header'] ?? true);

        $skipRows = $data['skip_rows'] ?? 0;
        if (! is_int($skipRows) || $skipRows < 0 || $skipRows > 50) {
            throw new InvalidColumnMapping('skip_rows harus bilangan bulat 0–50.');
        }

        $delimiter = $data['delimiter'] ?? null;
        if ($delimiter !== null && ! in_array($delimiter, CsvReader::DELIMITERS, true)) {
            throw new InvalidColumnMapping('delimiter harus koma, titik koma, atau tab (atau kosong untuk otomatis).');
        }

        $dateFormat = $data['date_format'] ?? null;
        if (! is_string($dateFormat) || ! in_array($dateFormat, StatementDate::FORMATS, true)) {
            throw new InvalidColumnMapping('date_format harus salah satu dari: '.implode(', ', StatementDate::FORMATS).'.');
        }

        $year = $data['year'] ?? null;
        if ($year !== null && (! is_int($year) || $year < 2000 || $year > 2100)) {
            throw new InvalidColumnMapping('year harus tahun 2000–2100.');
        }
        if ($year !== null && StatementDate::hasYear($dateFormat)) {
            throw new InvalidColumnMapping('year hanya untuk format tanggal tanpa tahun.');
        }

        $decimal = $data['decimal_separator'] ?? ',';
        if ($decimal !== ',' && $decimal !== '.') {
            throw new InvalidColumnMapping('decimal_separator harus "," atau ".".');
        }

        $column = fn (string $key, bool $required) => self::column($data[$key] ?? null, $key, $hasHeader, $required);

        $description = $data['description'] ?? null;
        $description = is_array($description) ? array_values($description) : [$description];
        if ($description === [] || count($description) > 5) {
            throw new InvalidColumnMapping('description harus 1–5 kolom.');
        }
        $description = array_map(fn ($c) => self::column($c, 'description', $hasHeader, true), $description);

        $amount = $column('amount', false);
        $debit = $column('debit', false);
        $credit = $column('credit', false);
        $direction = $column('direction', false);

        if (($amount === null) === ($debit === null && $credit === null)) {
            throw new InvalidColumnMapping('Pilih salah satu: kolom amount, atau pasangan debit + credit.');
        }
        if ($amount === null && ($debit === null || $credit === null)) {
            throw new InvalidColumnMapping('debit dan credit harus diisi berdua.');
        }
        if ($direction !== null && $amount === null) {
            throw new InvalidColumnMapping('direction hanya dipakai bersama kolom amount.');
        }

        return new self(
            hasHeader: $hasHeader,
            skipRows: $skipRows,
            delimiter: $delimiter,
            date: $column('date', true) ?? throw new InvalidColumnMapping('date wajib diisi.'),
            dateFormat: $dateFormat,
            year: $year,
            description: $description,
            amount: $amount,
            direction: $direction,
            debit: $debit,
            credit: $credit,
            decimalSeparator: $decimal,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'has_header' => $this->hasHeader,
            'skip_rows' => $this->skipRows,
            'delimiter' => $this->delimiter,
            'date' => $this->date,
            'date_format' => $this->dateFormat,
            'year' => $this->year,
            'description' => $this->description,
            'amount' => $this->amount,
            'direction' => $this->direction,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'decimal_separator' => $this->decimalSeparator,
        ], fn ($value) => $value !== null);
    }

    private static function column(mixed $value, string $key, bool $hasHeader, bool $required): int|string|null
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new InvalidColumnMapping("{$key} wajib diisi.");
            }

            return null;
        }
        if (is_int($value) && $value >= 0 && $value < 100) {
            return $value;
        }
        if (is_string($value) && mb_strlen(trim($value)) > 0 && mb_strlen($value) <= 100) {
            if (! $hasHeader) {
                throw new InvalidColumnMapping("{$key}: file tanpa header harus memakai indeks kolom (mulai 0).");
            }

            return trim($value);
        }

        throw new InvalidColumnMapping("{$key} harus nama header atau indeks kolom (0–99).");
    }
}
