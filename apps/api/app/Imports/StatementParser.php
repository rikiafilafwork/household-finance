<?php

namespace App\Imports;

use App\Imports\Exceptions\InvalidStatementFile;

/**
 * Satu format mutasi rekening → baris-baris staging (Keputusan #13).
 *
 * Kesalahan per baris (tanggal/nominal tak terbaca) TIDAK melempar: baris itu dikembalikan sebagai
 * `ParsedLine` invalid dengan alasannya, supaya preview bisa menampilkannya. Hanya kesalahan yang
 * membuat seluruh file tak terbaca (kolom header tidak ada, file kosong) yang melempar.
 */
interface StatementParser
{
    /**
     * @return iterable<int, ParsedLine>
     *
     * @throws InvalidStatementFile
     */
    public function lines(string $contents): iterable;
}
