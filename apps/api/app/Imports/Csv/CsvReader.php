<?php

namespace App\Imports\Csv;

use App\Imports\Exceptions\InvalidStatementFile;

/**
 * Isi file CSV mutasi → record-record sel, dengan urusan "file dari bank" diselesaikan di sini:
 *
 *  - encoding: BOM UTF-8 dibuang; BOM UTF-16 (ekspor "Unicode text" Excel) dikonversi; byte yang bukan
 *    UTF-8 valid dianggap Windows-1252 (ekspor Excel/bank lawas) lalu dikonversi ke UTF-8;
 *  - delimiter `,` / `;` / tab, otomatis (dihitung di luar tanda kutip) atau ditentukan;
 *  - record kosong dilewati, tapi tetap dihitung supaya `line` cocok dengan nomor baris di Excel.
 */
final class CsvReader
{
    public const DELIMITERS = [',', ';', "\t"];

    /**
     * @return list<array{line: int, cells: list<string>}>
     *
     * @throws InvalidStatementFile
     */
    public static function records(string $contents, ?string $delimiter = null): array
    {
        $contents = self::toUtf8($contents);
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);

        if (trim($contents) === '') {
            throw new InvalidStatementFile('File kosong.');
        }

        $delimiter ??= self::detectDelimiter($contents);
        if (! in_array($delimiter, self::DELIMITERS, true)) {
            throw new InvalidStatementFile('Delimiter harus koma, titik koma, atau tab.');
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new InvalidStatementFile('File tidak bisa dibaca.');
        }
        fwrite($stream, $contents);
        rewind($stream);

        $records = [];
        $line = 0;
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;
            $cells = array_map(fn ($cell) => trim((string) $cell), $cells);
            if (implode('', $cells) === '') {
                continue;
            }
            $records[] = ['line' => $line, 'cells' => $cells];
        }
        fclose($stream);

        return $records;
    }

    public static function toUtf8(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            return substr($contents, 3);
        }
        if (str_starts_with($contents, "\xFF\xFE")) {
            return mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($contents, "\xFE\xFF")) {
            return mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16BE');
        }
        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        return mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
    }

    /** Delimiter yang paling sering muncul di luar tanda kutip pada 20 baris pertama; seri → koma. */
    public static function detectDelimiter(string $contents): string
    {
        $sample = implode("\n", array_slice(explode("\n", $contents), 0, 20));
        $unquoted = preg_replace('/"(?:[^"]|"")*"/', '', $sample) ?? $sample;

        $best = ',';
        $bestCount = 0;
        foreach (self::DELIMITERS as $candidate) {
            $count = substr_count($unquoted, $candidate);
            if ($count > $bestCount) {
                [$best, $bestCount] = [$candidate, $count];
            }
        }

        return $best;
    }
}
