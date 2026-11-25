<?php

use App\Imports\DescriptionNormalizer;

it('normalizes descriptions', function (string $input, string $expected) {
    expect(DescriptionNormalizer::normalize($input))->toBe($expected);
})->with([
    'lowercase' => ['INDOMARET', 'indomaret'],
    'collapse whitespace' => ["  KOPI   KENANGAN \t JKT ", 'kopi kenangan jkt'],
    'punctuation to space' => ['TRSF E-BANKING DB', 'trsf e banking db'],
    'reference slash token dropped' => ['TRSF E-BANKING DB 2511/FTSCY/WS95051 KOPI', 'trsf e banking db kopi'],
    'alphanumeric reference dropped' => ['BI-FAST FT25112ABC123 BUDI', 'bi fast budi'],
    'long digits dropped' => ['QRIS 000123456789 WARUNG', 'qris warung'],
    'masked card dropped' => ['DEBIT 5264****1234 ALFAMART', 'debit alfamart'],
    'short number kept' => ['INDOMARET 1234 BOGOR', 'indomaret 1234 bogor'],
    'five digits kept' => ['GERAI 12345', 'gerai 12345'],
    'unicode letters' => ['KAFÉ ÇAĞ', 'kafé çağ'],
    'empty' => ['', ''],
    'only reference' => ['1234567890', ''],
]);

it('ignores formatting differences between exports of the same transaction', function () {
    expect(DescriptionNormalizer::normalize('TRSF E-BANKING DB 2511/FTSCY/WS95051  KOPI'))
        ->toBe(DescriptionNormalizer::normalize('trsf e-banking db 2611/FTSCY/WS11111 kopi'));
});

it('keeps different merchants apart', function () {
    expect(DescriptionNormalizer::normalize('INDOMARET 1234'))
        ->not->toBe(DescriptionNormalizer::normalize('INDOMARET 5678'));
});
