<?php

use App\Imports\FingerprintCalculator;

/** @return array{occurred_on: string, amount: int, description: string} */
function statementLine(string $date, int $amount, string $description): array
{
    return ['occurred_on' => $date, 'amount' => $amount, 'description' => $description];
}

it('is sha256 of account|date|amount|normalized description|occurrence index', function () {
    expect(FingerprintCalculator::fingerprint(7, '2026-11-03', -25_000, '  KOPI  Kenangan ', 1))
        ->toBe(hash('sha256', '7|2026-11-03|-25000|kopi kenangan|1'));
});

it('gives two identical legitimate lines in one file different fingerprints', function () {
    $fingerprints = FingerprintCalculator::forLines(1, [
        statementLine('2026-11-03', -25_000, 'KOPI KENANGAN'),
        statementLine('2026-11-03', -25_000, 'KOPI KENANGAN'),
    ]);

    expect($fingerprints[0])->not->toBe($fingerprints[1])
        ->and($fingerprints[0])->toBe(FingerprintCalculator::fingerprint(1, '2026-11-03', -25_000, 'KOPI KENANGAN', 0))
        ->and($fingerprints[1])->toBe(FingerprintCalculator::fingerprint(1, '2026-11-03', -25_000, 'KOPI KENANGAN', 1));
});

it('counts occurrences per identity, not per position', function () {
    $fingerprints = FingerprintCalculator::forLines(1, [
        'a' => statementLine('2026-11-03', -25_000, 'KOPI'),
        'b' => statementLine('2026-11-03', -50_000, 'BENSIN'),
        'c' => statementLine('2026-11-03', -25_000, 'kopi'),     // sama dengan 'a' setelah normalisasi
        'd' => statementLine('2026-11-04', -25_000, 'KOPI'),     // hari lain → index mulai dari 0 lagi
    ]);

    expect(array_keys($fingerprints))->toBe(['a', 'b', 'c', 'd'])
        ->and($fingerprints['b'])->toBe(FingerprintCalculator::fingerprint(1, '2026-11-03', -50_000, 'BENSIN', 0))
        ->and($fingerprints['c'])->toBe(FingerprintCalculator::fingerprint(1, '2026-11-03', -25_000, 'KOPI', 1))
        ->and($fingerprints['d'])->toBe(FingerprintCalculator::fingerprint(1, '2026-11-04', -25_000, 'KOPI', 0));
});

it('is stable when the same file is imported again', function () {
    $file = [
        statementLine('2026-11-01', 10_000_000, 'GAJI'),
        statementLine('2026-11-03', -25_000, 'KOPI'),
        statementLine('2026-11-03', -25_000, 'KOPI'),
    ];

    expect(FingerprintCalculator::forLines(1, $file))->toBe(FingerprintCalculator::forLines(1, $file));
});

it('ignores reference numbers that differ between exports', function () {
    expect(FingerprintCalculator::fingerprint(1, '2026-11-03', -25_000, 'TRSF DB 2511/FT/WS95051 KOPI', 0))
        ->toBe(FingerprintCalculator::fingerprint(1, '2026-11-03', -25_000, 'trsf db 2511/FT/WS99999 kopi', 0));
});

it('changes when any component changes', function (int $account, string $date, int $amount, string $desc, int $index) {
    expect(FingerprintCalculator::fingerprint($account, $date, $amount, $desc, $index))
        ->not->toBe(FingerprintCalculator::fingerprint(1, '2026-11-03', -25_000, 'KOPI', 0));
})->with([
    'account' => [2, '2026-11-03', -25_000, 'KOPI', 0],
    'date' => [1, '2026-11-04', -25_000, 'KOPI', 0],
    'amount' => [1, '2026-11-03', -26_000, 'KOPI', 0],
    'sign' => [1, '2026-11-03', 25_000, 'KOPI', 0],
    'description' => [1, '2026-11-03', -25_000, 'TEH', 0],
    'occurrence' => [1, '2026-11-03', -25_000, 'KOPI', 1],
]);

it('rejects invalid dates', function (string $date) {
    FingerprintCalculator::fingerprint(1, $date, -25_000, 'KOPI', 0);
})->throws(InvalidArgumentException::class)->with([
    'slash format' => '03/11/2026',
    'impossible day' => '2026-02-30',
    'empty' => '',
]);
