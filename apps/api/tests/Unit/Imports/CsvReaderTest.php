<?php

use App\Imports\Csv\CsvReader;
use App\Imports\Exceptions\InvalidStatementFile;

it('detects the delimiter outside quotes', function (string $contents, string $expected) {
    expect(CsvReader::detectDelimiter($contents))->toBe($expected);
})->with([
    'comma' => ["a,b,c\n1,2,3", ','],
    'semicolon with Indonesian decimals' => ["Tanggal;Ket;Jumlah\n03/11/2026;KOPI;25.000,00", ';'],
    'tab' => ["a\tb\tc\n1\t2\t3", "\t"],
    'comma with quoted English amounts' => ['03/11/2026,"KOPI; GULA","1,250,000.00"', ','],
    'tie falls back to comma' => ['abc', ','],
]);

it('splits records and keeps line numbers across blank lines', function () {
    $records = CsvReader::records("Tanggal;Ket\n\n03/11/2026;KOPI\r\n04/11/2026;\"TEH; MANIS\"\n;\n");

    expect($records)->toBe([
        ['line' => 1, 'cells' => ['Tanggal', 'Ket']],
        ['line' => 3, 'cells' => ['03/11/2026', 'KOPI']],
        ['line' => 4, 'cells' => ['04/11/2026', 'TEH; MANIS']],
    ]);
});

it('honours an explicit delimiter', function () {
    expect(CsvReader::records("a;b,c\n", ',')[0]['cells'])->toBe(['a;b', 'c']);
});

it('keeps backslashes literal', function () {
    expect(CsvReader::records("\"C:\\\\x\\\",b\n", ',')[0]['cells'])->toBe(['C:\\\\x\\', 'b']);
});

it('strips a UTF-8 BOM', function () {
    expect(CsvReader::records("\xEF\xBB\xBFTanggal,Ket\n")[0]['cells'][0])->toBe('Tanggal');
});

it('converts UTF-16LE with BOM', function () {
    $contents = "\xFF\xFE".mb_convert_encoding("Tanggal\tKet\n03/11/2026\tKAFÉ\n", 'UTF-16LE', 'UTF-8');

    expect(CsvReader::records($contents))->toBe([
        ['line' => 1, 'cells' => ['Tanggal', 'Ket']],
        ['line' => 2, 'cells' => ['03/11/2026', 'KAFÉ']],
    ]);
});

it('converts Windows-1252 bytes to UTF-8', function () {
    $contents = "Tanggal,Ket\n03/11/2026,KAF\xC9 \x80 5\n";   // É dan €

    expect(CsvReader::records($contents)[1]['cells'][1])->toBe('KAFÉ € 5');
});

it('rejects an empty file', function (string $contents) {
    CsvReader::records($contents);
})->throws(InvalidStatementFile::class)->with(['empty' => '', 'whitespace' => " \n\r\n ", 'bom only' => "\xEF\xBB\xBF"]);
