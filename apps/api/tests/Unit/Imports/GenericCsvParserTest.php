<?php

use App\Imports\ColumnMapping;
use App\Imports\Exceptions\InvalidColumnMapping;
use App\Imports\Exceptions\InvalidStatementFile;
use App\Imports\ParsedLine;
use App\Imports\Parsers\GenericCsvParser;

/**
 * @param  array<string, mixed>  $mapping
 * @return list<ParsedLine>
 */
function parseCsv(string $contents, array $mapping, string $uploadedOn = '2026-11-25'): array
{
    $parser = new GenericCsvParser(ColumnMapping::fromArray($mapping), new DateTimeImmutable($uploadedOn));

    return [...$parser->lines($contents)];
}

/**
 * @param  list<ParsedLine>  $lines
 * @return list<array{int, ?string, ?int, ?string, ?string}>
 */
function summarize(array $lines): array
{
    return array_map(fn (ParsedLine $l) => [$l->rowNumber, $l->occurredOn, $l->amount, $l->description, $l->error], $lines);
}

it('parses an Indonesian semicolon file with debit and credit columns', function () {
    $csv = <<<'CSV'
        Tanggal;Keterangan;Cabang;Debet;Kredit
        01/11/2026;GAJI NOVEMBER;0000;;10.000.000,00
        03/11/2026;KOPI KENANGAN;0123;25.000,00;
        03/11/2026;KOPI KENANGAN;0123;25.000,00;0,00
        CSV;

    $lines = parseCsv($csv, [
        'date' => 'Tanggal', 'date_format' => 'd/m/Y',
        'description' => ['Keterangan'], 'debit' => 'Debet', 'credit' => 'Kredit',
    ]);

    expect(summarize($lines))->toBe([
        [2, '2026-11-01', 10_000_000, 'GAJI NOVEMBER', null],
        [3, '2026-11-03', -25_000, 'KOPI KENANGAN', null],
        [4, '2026-11-03', -25_000, 'KOPI KENANGAN', null],
    ])->and($lines[1]->raw)->toBe([
        'Tanggal' => '03/11/2026', 'Keterangan' => 'KOPI KENANGAN', 'Cabang' => '0123', 'Debet' => '25.000,00', 'Kredit' => '',
    ]);
});

it('parses an English comma file with a DB/CR suffixed amount and a preamble', function () {
    $csv = <<<'CSV'
        Informasi Rekening - Mutasi Rekening
        No. rekening : ,1234567890
        Tanggal,Keterangan,Cabang,Jumlah,Saldo
        03/11,"TRSF E-BANKING DB 0311/FTSCY/WS95051 KOPI",0000,"25,000.00 DB","9,975,000.00"
        05/11,"BUNGA",0000,"1,234.00 CR","9,976,234.00"
        CSV;

    $lines = parseCsv($csv, [
        'skip_rows' => 2, 'date' => 'Tanggal', 'date_format' => 'd/m', 'description' => 'Keterangan',
        'amount' => 'Jumlah', 'decimal_separator' => '.',
    ]);

    expect(summarize($lines))->toBe([
        [4, '2026-11-03', -25_000, 'TRSF E-BANKING DB 0311/FTSCY/WS95051 KOPI', null],
        [5, '2026-11-05', 1_234, 'BUNGA', null],
    ]);
});

it('uses a separate direction column', function () {
    $csv = "tgl,ket,nominal,arah\n2026-11-03,KOPI,25000,D\n2026-11-04,REFUND,-5000,K\n";

    $lines = parseCsv($csv, [
        'date' => 'TGL', 'date_format' => 'Y-m-d', 'description' => 'ket', 'amount' => 'nominal', 'direction' => 'arah',
    ]);

    expect(summarize($lines))->toBe([
        [2, '2026-11-03', -25_000, 'KOPI', null],
        [3, '2026-11-04', 5_000, 'REFUND', null],
    ]);
});

it('reads files without a header by column index and joins description columns', function () {
    $lines = parseCsv("03/11/2026,QRIS,WARUNG BU TINI,-15.000\n", [
        'has_header' => false, 'date' => 0, 'date_format' => 'd/m/Y', 'description' => [1, 2], 'amount' => 3,
    ]);

    expect(summarize($lines))->toBe([[1, '2026-11-03', -15_000, 'QRIS WARUNG BU TINI', null]])
        ->and($lines[0]->raw)->toBe(['03/11/2026', 'QRIS', 'WARUNG BU TINI', '-15.000']);
});

it('marks bad rows invalid with a reason and keeps parsing', function () {
    $csv = <<<'CSV'
        Tanggal;Keterangan;Debet;Kredit
        PEND;KOPI;25.000;
        03/11/2026;BUNGA;;1.234,56
        03/11/2026;ANEH;25.000;25.000
        03/11/2026;NOL;0;0
        03/11/2026;PENDEK
        04/11/2026;TEH;-;12.000
        CSV;

    $lines = parseCsv($csv, [
        'date' => 'Tanggal', 'date_format' => 'd/m/Y', 'description' => 'Keterangan', 'debit' => 'Debet', 'credit' => 'Kredit',
    ]);

    expect(array_map(fn (ParsedLine $l) => [$l->rowNumber, $l->isValid()], $lines))->toBe([
        [2, false], [3, false], [4, false], [5, false], [6, false], [7, true],
    ])
        ->and($lines[0]->error)->toContain('PEND')
        ->and($lines[1]->error)->toContain('sen')
        ->and($lines[2]->error)->toContain('sama-sama terisi')
        ->and($lines[3]->error)->toBe('Nominal nol.')
        ->and($lines[4]->error)->toBe('Kolom ke-3 tidak ada di baris ini.')
        ->and($lines[5]->amount)->toBe(12_000);
});

it('flags a row whose amount column is missing', function () {
    $lines = parseCsv("Tanggal;Ket;Jumlah\n03/11/2026;KOPI\n", [
        'date' => 'Tanggal', 'date_format' => 'd/m/Y', 'description' => 'Ket', 'amount' => 'Jumlah',
    ]);

    expect($lines[0]->isValid())->toBeFalse()
        ->and($lines[0]->error)->toBe('Kolom ke-3 tidak ada di baris ini.');
});

it('truncates long descriptions to the column size', function () {
    $lines = parseCsv('03/11/2026,'.str_repeat('é', 300).",-1.000\n", [
        'has_header' => false, 'date' => 0, 'date_format' => 'd/m/Y', 'description' => 1, 'amount' => 2,
    ]);

    expect(mb_strlen((string) $lines[0]->description))->toBe(255);
});

it('rejects a file whose header lacks a mapped column', function () {
    parseCsv("Tanggal;Ket;Jumlah\n03/11/2026;KOPI;25.000\n", [
        'date' => 'Tanggal', 'date_format' => 'd/m/Y', 'description' => 'Keterangan', 'amount' => 'Jumlah',
    ]);
})->throws(InvalidStatementFile::class, 'Kolom "Keterangan" tidak ada di header');

it('rejects a file with only a header', function () {
    parseCsv("Tanggal;Ket;Jumlah\n", ['date' => 0, 'date_format' => 'd/m/Y', 'description' => 1, 'amount' => 2]);
})->throws(InvalidStatementFile::class, 'tidak berisi baris mutasi');

describe('column mapping', function () {
    $base = ['date' => 'Tanggal', 'date_format' => 'd/m/Y', 'description' => 'Ket', 'amount' => 'Jumlah'];

    it('round-trips through toArray for storage as a preset', function () use ($base) {
        $mapping = ColumnMapping::fromArray($base + ['delimiter' => ';', 'decimal_separator' => '.']);

        expect(ColumnMapping::fromArray($mapping->toArray()))->toEqual($mapping)
            ->and($mapping->toArray())->toBe([
                'has_header' => true, 'skip_rows' => 0, 'delimiter' => ';', 'date' => 'Tanggal', 'date_format' => 'd/m/Y',
                'description' => ['Ket'], 'amount' => 'Jumlah', 'decimal_separator' => '.',
            ]);
    });

    it('rejects invalid mappings', function (array $override, string $message) {
        expect(fn () => ColumnMapping::fromArray(array_merge(
            ['date' => 'Tanggal', 'date_format' => 'd/m/Y', 'description' => 'Ket', 'amount' => 'Jumlah'],
            $override,
        )))->toThrow(InvalidColumnMapping::class, $message);
    })->with([
        'missing date' => [['date' => null], 'date wajib'],
        'missing description' => [['description' => null], 'description wajib'],
        'unsupported date format' => [['date_format' => 'Y-m-d H:i'], 'date_format'],
        'no amount at all' => [['amount' => null], 'Pilih salah satu'],
        'amount and debit' => [['debit' => 'Debet', 'credit' => 'Kredit'], 'Pilih salah satu'],
        'debit without credit' => [['amount' => null, 'debit' => 'Debet'], 'berdua'],
        'direction with debit/credit' => [['amount' => null, 'debit' => 'D', 'credit' => 'K', 'direction' => 'Arah'], 'direction'],
        'names without header' => [['has_header' => false], 'indeks kolom'],
        'negative index' => [['date' => -1], 'indeks kolom'],
        'bad delimiter' => [['delimiter' => '|'], 'delimiter'],
        'bad decimal' => [['decimal_separator' => ' '], 'decimal_separator'],
        'year with full date' => [['year' => 2026], 'tanpa tahun'],
        'skip rows too many' => [['skip_rows' => 99], 'skip_rows'],
        'too many description columns' => [['description' => ['a', 'b', 'c', 'd', 'e', 'f']], '1–5'],
    ]);
});
