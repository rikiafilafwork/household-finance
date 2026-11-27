<?php

use App\Savings\MonthlyNeeded;

/*
| "Perlu per bulan" = ceil(sisa / bulan kalender dari bulan today sampai bulan tenggat, inklusif, minimal 1).
*/

it('computes the monthly amount needed', function (int $target, int $allocated, ?string $targetDate, string $today, ?int $expected) {
    expect(MonthlyNeeded::for($target, $allocated, $targetDate, $today))->toBe($expected);
})->with([
    'no deadline' => [12_000_000, 2_000_000, null, '2026-11-27', null],
    'eight calendar months Nov-Jun' => [10_000_000, 3_000_000, '2027-06-15', '2026-11-27', 875_000],
    'already reached' => [10_000_000, 10_000_000, '2027-06-15', '2026-11-27', 0],
    'over-allocated counts as reached' => [10_000_000, 12_000_000, '2027-06-15', '2026-11-27', 0],
    'reached without deadline' => [10_000_000, 10_000_000, null, '2026-11-27', null],
    'deadline at the end of this month' => [10_000_000, 3_000_000, '2026-11-30', '2026-11-27', 7_000_000],
    'deadline already passed' => [10_000_000, 3_000_000, '2026-10-01', '2026-11-27', 7_000_000],
    'rounds up' => [1_000_000, 0, '2027-01-01', '2026-11-01', 333_334],
]);
