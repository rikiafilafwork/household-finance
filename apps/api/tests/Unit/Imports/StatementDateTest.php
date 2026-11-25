<?php

use App\Imports\Exceptions\InvalidStatementValue;
use App\Imports\StatementDate;

function uploadedOn(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date);
}

it('parses supported formats', function (string $value, string $format, string $expected) {
    expect(StatementDate::parse($value, $format, uploadedOn('2026-11-25')))->toBe($expected);
})->with([
    'd/m/Y' => ['03/11/2026', 'd/m/Y', '2026-11-03'],
    'd/m/Y without padding' => ['3/1/2026', 'd/m/Y', '2026-01-03'],
    'd-m-Y' => ['03-11-2026', 'd-m-Y', '2026-11-03'],
    'd.m.Y' => ['03.11.2026', 'd.m.Y', '2026-11-03'],
    'Y-m-d' => ['2026-11-03', 'Y-m-d', '2026-11-03'],
    'd/m/y' => ['03/11/26', 'd/m/y', '2026-11-03'],
    'm/d/Y' => ['11/03/2026', 'm/d/Y', '2026-11-03'],
    'd M Y english' => ['03 Nov 2026', 'd M Y', '2026-11-03'],
    'd M Y indonesian Mei' => ['03 Mei 2026', 'd M Y', '2026-05-03'],
    'd M Y indonesian Agu' => ['17 Agu 2026', 'd M Y', '2026-08-17'],
    'd M Y indonesian Agt' => ['17 Agt 2026', 'd M Y', '2026-08-17'],
    'd M Y indonesian Okt' => ['28 Okt 2026', 'd M Y', '2026-10-28'],
    'd M Y full month' => ['25 Desember 2025', 'd M Y', '2025-12-25'],
    'd-M-Y lowercase' => ['03-nop-2026', 'd-M-Y', '2026-11-03'],
]);

it('treats day/month order as given, not guessed', function () {
    expect(StatementDate::parse('03/11/2026', 'd/m/Y', uploadedOn('2026-11-25')))->toBe('2026-11-03')
        ->and(StatementDate::parse('03/11/2026', 'm/d/Y', uploadedOn('2026-11-25')))->toBe('2026-03-11');
});

describe('dates without a year', function () {
    it('uses the upload year', function () {
        expect(StatementDate::parse('03/11', 'd/m', uploadedOn('2026-11-25')))->toBe('2026-11-03');
    });

    it('goes back a year for a December statement uploaded in January', function () {
        expect(StatementDate::parse('28/12', 'd/m', uploadedOn('2027-01-05')))->toBe('2026-12-28')
            ->and(StatementDate::parse('02/01', 'd/m', uploadedOn('2027-01-05')))->toBe('2027-01-02');
    });

    it('accepts the upload day itself', function () {
        expect(StatementDate::parse('25/11', 'd/m', uploadedOn('2026-11-25')))->toBe('2026-11-25');
    });

    it('finds the leap year for 29 Feb', function () {
        expect(StatementDate::parse('29/02', 'd/m', uploadedOn('2029-01-10')))->toBe('2028-02-29');
    });

    it('uses an explicit year when the mapping gives one', function () {
        expect(StatementDate::parse('28/12', 'd/m', uploadedOn('2027-01-05'), 2027))->toBe('2027-12-28')
            ->and(StatementDate::parse('03 Okt', 'd M', uploadedOn('2026-11-25'), 2025))->toBe('2025-10-03');
    });
});

it('rejects values that do not match', function (string $value, string $format) {
    StatementDate::parse($value, $format, uploadedOn('2026-11-25'));
})->throws(InvalidStatementValue::class)->with([
    'impossible day' => ['31/02/2026', 'd/m/Y'],
    'month 13' => ['03/13/2026', 'd/m/Y'],
    'wrong separator' => ['03-11-2026', 'd/m/Y'],
    'trailing text' => ['03/11/2026 PEND', 'd/m/Y'],
    'empty' => ['', 'd/m/Y'],
    'pending marker' => ['PEND', 'd/m'],
    'unknown month' => ['03 Xyz 2026', 'd M Y'],
    'unsupported format' => ['2026-11-03 10:00', 'Y-m-d H:i'],
    'impossible day without year' => ['30/02', 'd/m'],
]);
