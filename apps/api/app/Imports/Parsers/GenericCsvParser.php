<?php

namespace App\Imports\Parsers;

use App\Imports\ColumnMapping;
use App\Imports\Csv\CsvReader;
use App\Imports\Exceptions\InvalidStatementFile;
use App\Imports\Exceptions\InvalidStatementValue;
use App\Imports\ParsedLine;
use App\Imports\StatementAmount;
use App\Imports\StatementDate;
use App\Imports\StatementParser;
use DateTimeImmutable;

/**
 * CSV apa pun dengan pemetaan kolom dari user (`ColumnMapping`). Parser khusus bank (#52) dipakai
 * kalau format banknya sudah dikenal; ini jalan keluarnya untuk semua bank lain.
 */
final class GenericCsvParser implements StatementParser
{
    /**
     * @param  DateTimeImmutable  $reference  acuan tahun untuk format tanggal tanpa tahun (tanggal upload)
     */
    public function __construct(
        private readonly ColumnMapping $mapping,
        private readonly DateTimeImmutable $reference,
    ) {}

    public function lines(string $contents): iterable
    {
        $records = array_slice(CsvReader::records($contents, $this->mapping->delimiter), $this->mapping->skipRows);

        $header = null;
        if ($this->mapping->hasHeader) {
            $first = array_shift($records) ?? throw new InvalidStatementFile('Baris header tidak ditemukan.');
            $header = $first['cells'];
        }

        if ($records === []) {
            throw new InvalidStatementFile('File tidak berisi baris mutasi.');
        }

        $columns = $this->resolveColumns($header);

        foreach ($records as $record) {
            $cells = $record['cells'];
            $raw = $header === null ? $cells : $this->combine($header, $cells);

            try {
                yield $this->line($record['line'], $raw, $cells, $columns);
            } catch (InvalidStatementValue $e) {
                yield ParsedLine::invalid($record['line'], $raw, $e->getMessage());
            }
        }
    }

    /**
     * @param  array<int|string, string>  $raw
     * @param  list<string>  $cells
     * @param  array{date: int, description: list<int>, amount: ?int, direction: ?int, debit: ?int, credit: ?int}  $columns
     */
    private function line(int $lineNumber, array $raw, array $cells, array $columns): ParsedLine
    {
        $cell = fn (int $index) => $cells[$index] ?? throw new InvalidStatementValue('Kolom ke-'.($index + 1).' tidak ada di baris ini.');

        $date = StatementDate::parse($cell($columns['date']), $this->mapping->dateFormat, $this->reference, $this->mapping->year);

        $description = implode(' ', array_filter(
            array_map(fn (int $i) => $cells[$i] ?? '', $columns['description']),
            fn (string $part) => $part !== '',
        ));

        $amount = $columns['amount'] !== null
            ? $this->singleAmount($cell($columns['amount']), $columns['direction'] === null ? null : $cell($columns['direction']))
            : $this->debitCredit($cells, (int) $columns['debit'], (int) $columns['credit']);

        if ($amount === 0) {
            throw new InvalidStatementValue('Nominal nol.');
        }

        return ParsedLine::valid($lineNumber, $raw, $date, $amount, $description);
    }

    private function singleAmount(string $value, ?string $direction): int
    {
        $amount = StatementAmount::parse($value, $this->mapping->decimalSeparator);

        return $direction === null ? $amount : abs($amount) * StatementAmount::direction($direction);
    }

    /**
     * Sel kosong atau `-` = 0. Kolom yang terpotong di akhir baris dianggap kosong, kecuali keduanya
     * hilang (baris jelas terpotong).
     *
     * @param  list<string>  $cells
     */
    private function debitCredit(array $cells, int $debitColumn, int $creditColumn): int
    {
        if (! isset($cells[$debitColumn]) && ! isset($cells[$creditColumn])) {
            throw new InvalidStatementValue('Kolom ke-'.(min($debitColumn, $creditColumn) + 1).' tidak ada di baris ini.');
        }

        $debit = $cells[$debitColumn] ?? '';
        $credit = $cells[$creditColumn] ?? '';

        $out = in_array($debit, ['', '-'], true) ? 0 : abs(StatementAmount::parse($debit, $this->mapping->decimalSeparator));
        $in = in_array($credit, ['', '-'], true) ? 0 : abs(StatementAmount::parse($credit, $this->mapping->decimalSeparator));

        if ($out !== 0 && $in !== 0) {
            throw new InvalidStatementValue('Kolom debit dan kredit sama-sama terisi.');
        }

        return $in - $out;
    }

    /**
     * Nama header → indeks kolom.
     *
     * @param  list<string>|null  $header
     * @return array{date: int, description: list<int>, amount: ?int, direction: ?int, debit: ?int, credit: ?int}
     *
     * @throws InvalidStatementFile
     */
    private function resolveColumns(?array $header): array
    {
        $resolve = function (int|string|null $column) use ($header): ?int {
            if ($column === null || is_int($column)) {
                return $column;
            }
            foreach ($header ?? [] as $index => $name) {
                if (mb_strtolower(trim($name)) === mb_strtolower($column)) {
                    return $index;
                }
            }

            throw new InvalidStatementFile("Kolom \"{$column}\" tidak ada di header (".implode(', ', $header ?? []).').');
        };

        return [
            'date' => (int) $resolve($this->mapping->date),
            'description' => array_map(fn ($c) => (int) $resolve($c), $this->mapping->description),
            'amount' => $resolve($this->mapping->amount),
            'direction' => $resolve($this->mapping->direction),
            'debit' => $resolve($this->mapping->debit),
            'credit' => $resolve($this->mapping->credit),
        ];
    }

    /**
     * Sel berkunci nama header; kolom tanpa nama/duplikat memakai indeksnya.
     *
     * @param  list<string>  $header
     * @param  list<string>  $cells
     * @return array<int|string, string>
     */
    private function combine(array $header, array $cells): array
    {
        $raw = [];
        foreach ($cells as $index => $value) {
            $name = $header[$index] ?? '';
            $raw[$name === '' || array_key_exists($name, $raw) ? $index : $name] = $value;
        }

        return $raw;
    }
}
