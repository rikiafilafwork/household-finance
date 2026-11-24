<?php

use App\Enums\Frequency;
use App\Plan\MonthlyDueDate;
use App\Plan\PlanMonth;
use Carbon\CarbonImmutable;

/*
| Keputusan #9: due_on dihitung per bulan dari day_of_month, dibatasi hari terakhir bulan itu.
| Tidak bergulir dari bulan sebelumnya → tanggal 31 tidak "terjebak" di 28 setelah Februari.
*/

$due = fn (string $month, int $day) => MonthlyDueDate::in(PlanMonth::parse($month), $day);

it('keeps day 31 through a whole year without drifting', function () use ($due) {
    $months = array_map(fn (int $m) => sprintf('2026-%02d', $m), range(1, 12));

    expect(array_map(fn (string $m) => $due($m, 31), $months))->toBe([
        '2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30',
        '2026-07-31', '2026-08-31', '2026-09-30', '2026-10-31', '2026-11-30', '2026-12-31',
    ]);
});

it('clamps to the last day of February, including leap years', function (string $month, int $day, string $expected) use ($due) {
    expect($due($month, $day))->toBe($expected);
})->with([
    '31 in 2026 (not leap)' => ['2026-02', 31, '2026-02-28'],
    '29 in 2026 (not leap)' => ['2026-02', 29, '2026-02-28'],
    '31 in 2028 (leap)' => ['2028-02', 31, '2028-02-29'],
    '29 in 2028 (leap)' => ['2028-02', 29, '2028-02-29'],
    '30 in 2100 (divisible by 100, not leap)' => ['2100-02', 30, '2100-02-28'],
    '30 in 2000 (divisible by 400, leap)' => ['2000-02', 30, '2000-02-29'],
    '28 stays 28' => ['2026-02', 28, '2026-02-28'],
]);

it('returns the day itself when the month is long enough', function () use ($due) {
    expect($due('2026-11', 1))->toBe('2026-11-01')
        ->and($due('2026-11', 25))->toBe('2026-11-25')
        ->and($due('2026-12', 31))->toBe('2026-12-31');
});

it('rejects days outside 1..31 instead of guessing', function (int $day) use ($due) {
    $due('2026-11', $day);
})->with([0, 32, -1])->throws(InvalidArgumentException::class);

it('agrees with Carbon on the number of days in every month of 1900–2400', function () {
    for ($year = 1900; $year <= 2400; $year++) {
        foreach (range(1, 12) as $month) {
            expect(MonthlyDueDate::daysIn($year, $month))
                ->toBe(CarbonImmutable::create($year, $month, 1)->daysInMonth);
        }
    }
});

it('lets PlanMonth::day use the same rule', function () {
    expect(PlanMonth::parse('2026-02')->day(31))->toBe('2026-02-28')
        ->and(PlanMonth::parse('2028-02')->day(30))->toBe('2028-02-29');
});

describe('occursIn', function () {
    it('produces monthly templates every month', function () {
        foreach (['2026-01', '2026-02', '2026-12'] as $m) {
            expect(MonthlyDueDate::occursIn(PlanMonth::parse($m), Frequency::Monthly, null))->toBeTrue();
        }
    });

    it('produces yearly templates only in their month', function () {
        $occurs = array_filter(
            array_map(fn (int $m) => sprintf('2026-%02d', $m), range(1, 12)),
            fn (string $m) => MonthlyDueDate::occursIn(PlanMonth::parse($m), Frequency::Yearly, 3),
        );

        expect(array_values($occurs))->toBe(['2026-03'])
            ->and(MonthlyDueDate::occursIn(PlanMonth::parse('2027-03'), Frequency::Yearly, 3))->toBeTrue();
    });

    it('never produces a yearly template without a month, and refuses weekly', function () {
        expect(MonthlyDueDate::occursIn(PlanMonth::parse('2026-03'), Frequency::Yearly, null))->toBeFalse()
            ->and(fn () => MonthlyDueDate::occursIn(PlanMonth::parse('2026-03'), Frequency::Weekly, null))
            ->toThrow(InvalidArgumentException::class);
    });
});
