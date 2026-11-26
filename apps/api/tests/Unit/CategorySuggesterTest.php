<?php

use App\Enums\CategoryKind;
use App\Imports\CategorySuggester;

it('derives the keyword from the normalized description without numbers', function (string $description, ?string $expected) {
    expect(CategorySuggester::keywordFor($description))->toBe($expected);
})->with([
    'store number dropped' => ['INDOMARET 1234', 'indomaret'],
    'reference dropped' => ['TRSF E-BANKING DB 1011/FTSCY/WS95051 BUDI', 'trsf e banking db budi'],
    'meter number dropped' => ['LISTRIK PLN 512345678901', 'listrik pln'],
    'mixed token dropped' => ['QRIS KOPI2GO JKT', 'qris jkt'],
    'only numbers' => ['1234 5678', null],
    'empty' => ['', null],
]);

it('caps the keyword at 100 characters on a word boundary', function () {
    $keyword = CategorySuggester::keywordFor(str_repeat('panjang ', 30));

    expect(mb_strlen((string) $keyword))->toBeLessThanOrEqual(100)
        ->and($keyword)->toEndWith('panjang');
});

$kinds = [1 => CategoryKind::Expense, 2 => CategoryKind::Expense, 3 => CategoryKind::Income, 4 => CategoryKind::Expense];

it('suggests by whole words in order, longest rule first', function (string $description, int $amount, ?int $expected) use ($kinds) {
    $suggester = new CategorySuggester([
        'indomaret' => 1,
        'trsf e banking db' => 2,
        'trsf e banking db budi' => 4,
        'gaji' => 3,
        'pln' => 2,
    ], $kinds);

    expect($suggester->suggest($description, $amount))->toBe($expected);
})->with([
    'store elsewhere' => ['INDOMARET 5678 BOGOR', -87_500, 1],
    'longest wins' => ['TRSF E-BANKING DB 2011/FTSCY/WS95051 BUDI', -500_000, 4],
    'shorter when payee differs' => ['TRSF E-BANKING DB 2011/FTSCY/WS95051 SITI', -500_000, 2],
    'income rule for money in' => ['GAJI NOVEMBER PT CONTOH', 10_000_000, 3],
    'expense rule never on money in' => ['INDOMARET REFUND', 25_000, null],
    'income rule never on money out' => ['GAJI DIKEMBALIKAN', -10_000, null],
    'not a partial word' => ['KAPLNX', -1_000, null],
    'words out of order' => ['DB BANKING E TRSF', -1_000, null],
    'no rule' => ['KOPI KENANGAN', -25_000, null],
    'zero amount' => ['INDOMARET', 0, null],
]);

it('skips rules pointing at categories that are archived or gone', function () {
    $suggester = new CategorySuggester(['indomaret' => 9], [1 => CategoryKind::Expense]);

    expect($suggester->suggest('INDOMARET', -1_000))->toBeNull();
});

it('prefers the newer rule when two match with the same length', function () {
    // Urutan array = urutan id (lama → baru).
    $suggester = new CategorySuggester(['kopi' => 1, 'susu' => 2], [1 => CategoryKind::Expense, 2 => CategoryKind::Expense]);

    expect($suggester->suggest('KOPI SUSU', -1_000))->toBe(2);
});
