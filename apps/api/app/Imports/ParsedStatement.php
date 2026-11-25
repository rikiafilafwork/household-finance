<?php

namespace App\Imports;

/**
 * Hasil parse satu file: nama header (kalau ada) + baris-baris mutasi. Header disimpan terpisah dari
 * baris karena kolom JSON MySQL tidak mempertahankan urutan kunci objek; `ParsedLine::$raw` berupa
 * daftar sel sesuai urutan file.
 */
final readonly class ParsedStatement
{
    /**
     * @param  list<string>|null  $headers
     * @param  list<ParsedLine>  $lines
     */
    public function __construct(
        public ?array $headers,
        public array $lines,
    ) {}
}
