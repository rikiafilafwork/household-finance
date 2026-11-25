<?php

use App\Imports\Exceptions\InvalidStatementValue;
use App\Imports\StatementAmount;

it('parses Indonesian formatted amounts', function (string $input, int $expected) {
    expect(StatementAmount::parse($input, ','))->toBe($expected);
})->with([
    'grouped' => ['1.250.000', 1_250_000],
    'zero decimals' => ['1.250.000,00', 1_250_000],
    'negative' => ['-25.000', -25_000],
    'rupiah prefix' => ['Rp 25.000', 25_000],
    'plain digits' => ['25000', 25_000],
    'parentheses' => ['(25.000)', -25_000],
    'surrounding spaces' => ['  25.000  ', 25_000],
]);

it('parses English formatted amounts when the decimal separator is a dot', function (string $input, int $expected) {
    expect(StatementAmount::parse($input, '.'))->toBe($expected);
})->with([
    'grouped' => ['1,250,000', 1_250_000],
    'zero decimals' => ['1,250,000.00', 1_250_000],
    'plain with decimals' => ['25000.00', 25_000],
    'negative' => ['-25,000.00', -25_000],
]);

it('reads DB/CR direction suffixes', function (string $input, string $decimal, int $expected) {
    expect(StatementAmount::parse($input, $decimal))->toBe($expected);
})->with([
    'DB with space' => ['1,250,000.00 DB', '.', -1_250_000],
    'CR with space' => ['1,250,000.00 CR', '.', 1_250_000],
    'DB glued' => ['25.000DB', ',', -25_000],
    'lowercase cr' => ['25.000 cr', ',', 25_000],
    'D' => ['25.000 D', ',', -25_000],
    'K (kredit)' => ['25.000 K', ',', 25_000],
    'DR' => ['25.000 DR', ',', -25_000],
    'Debet' => ['25.000 Debet', ',', -25_000],
    'Kredit' => ['25.000 Kredit', ',', 25_000],
    'trailing dot' => ['25.000 DB.', ',', -25_000],
]);

it('never treats a thousands dot as a decimal in Indonesian format', function () {
    expect(StatementAmount::parse('1.250', ','))->toBe(1_250);
});

it('rejects unreadable amounts', function (string $input, string $decimal) {
    StatementAmount::parse($input, $decimal);
})->throws(InvalidStatementValue::class)->with([
    'empty' => ['', ','],
    'non-zero cents Indonesian' => ['1.250,50', ','],
    'non-zero cents English' => ['1,250.50', '.'],
    'English amount read as Indonesian' => ['1,250,000.00', ','],
    'Indonesian amount read as English' => ['1.250.000,00', '.'],
    'unknown suffix' => ['25.000 XX', ','],
    'two direction markers' => ['-25.000 DB', ','],
    'parentheses and suffix' => ['(25.000) DB', ','],
    'letters' => ['dua lima', ','],
]);

it('maps direction markers', function (string $marker, int $expected) {
    expect(StatementAmount::direction($marker))->toBe($expected);
})->with([
    ['DB', -1], ['db', -1], ['D', -1], ['Debet', -1], ['DEBIT', -1],
    ['CR', 1], ['K', 1], ['Kredit', 1], ['credit', 1], [' CR. ', 1],
]);
