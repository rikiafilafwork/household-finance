<?php

use App\Exceptions\InvalidMoneyFormat;
use App\Support\Money;

describe('parse', function () {
    it('parses Indonesian formatted amounts', function (string|int $input, int $expected) {
        expect(Money::parse($input))->toBe($expected);
    })->with([
        'rupiah prefix' => ['Rp1.250.000', 1_250_000],
        'prefix with space' => ['Rp 1.250.000', 1_250_000],
        'lowercase prefix with dot' => ['rp. 25.000', 25_000],
        'thousands only' => ['1.250', 1_250],
        'plain digits' => ['1250000', 1_250_000],
        'zero decimal ,00' => ['1.250.000,00', 1_250_000],
        'zero decimal ,0' => ['Rp50.000,0', 50_000],
        'surrounding whitespace' => ['  75.000  ', 75_000],
        'negative before prefix' => ['-Rp50.000', -50_000],
        'negative after prefix' => ['Rp-50.000', -50_000],
        'zero' => ['0', 0],
        'leading zeros' => ['000123', 123],
        'integer passthrough' => [1_250_000, 1_250_000],
        'large amount' => ['Rp9.000.000.000.000', 9_000_000_000_000],
    ]);

    it('never treats a dot as a decimal separator', function () {
        // Number("1.250") di JS = 1.25. Di sini harus 1250.
        expect(Money::parse('1.250'))->toBe(1250)
            ->and(Money::parse('1.000.000'))->toBe(1_000_000);
    });

    it('rejects invalid input', function (string $input) {
        Money::parse($input);
    })->throws(InvalidMoneyFormat::class)->with([
        'non-zero decimal' => '1.250,50',
        'non-zero single decimal' => '1.250,5',
        'three decimal digits' => '1.250,000',
        'empty' => '',
        'prefix only' => 'Rp',
        'letters' => 'seratus ribu',
        'wrong grouping 1.25' => '1.25',
        'wrong grouping 12.50.000' => '12.50.000',
        'trailing dot' => '1.250.',
        'leading dot' => '.250',
        'english comma grouping' => '1,250,000',
        'double minus' => '--50.000',
        'minus both sides of prefix' => '-Rp-50.000',
        'float notation' => '1e6',
        'overflow' => '99999999999999999999',
    ]);
});

describe('format', function () {
    it('formats integers as rupiah', function (int $amount, string $expected) {
        expect(Money::format($amount))->toBe($expected);
    })->with([
        [1_250_000, 'Rp1.250.000'],
        [1_250, 'Rp1.250'],
        [999, 'Rp999'],
        [0, 'Rp0'],
        [-50_000, '-Rp50.000'],
        [1_000_000_000_000, 'Rp1.000.000.000.000'],
    ]);

    it('round-trips through parse', function (int $amount) {
        expect(Money::parse(Money::format($amount)))->toBe($amount);
    })->with([0, 1, 999, 1_000, 25_000, 1_250_000, -75_500, 123_456_789_012]);
});

describe('percent', function () {
    it('computes integer percentages rounded down', function (int $part, int $whole, int $expected) {
        expect(Money::percentOf($part, $whole))->toBe($expected);
    })->with([
        [80_000, 100_000, 80],
        [79_999, 100_000, 79],
        [150_000, 100_000, 150],
        [1, 3, 33],
        [0, 500_000, 0],
    ]);

    it('rejects a non-positive whole', function () {
        Money::percentOf(1, 0);
    })->throws(InvalidArgumentException::class);

    it('compares thresholds without division', function () {
        // spent * 100 >= limit * 80
        expect(Money::reachesPercent(79_999, 100_000, 80))->toBeFalse()
            ->and(Money::reachesPercent(80_000, 100_000, 80))->toBeTrue()
            ->and(Money::reachesPercent(1_000_000, 1_000_000, 100))->toBeTrue()
            ->and(Money::reachesPercent(999_999, 1_000_000, 100))->toBeFalse();
    });
});
