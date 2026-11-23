<?php

use App\Enums\AccountType;
use App\Enums\CategoryKind;
use App\Enums\Frequency;
use App\Enums\TransactionType;

// Nilai enum harus persis sama dengan ENUM di schema Architecture.md.
/**
 * @param  list<string>  $actual
 * @param  list<string>  $expected
 */
it('matches the database schema values', function (array $actual, array $expected) {
    expect($actual)->toBe($expected);
})->with([
    'account type' => [AccountType::values(), ['bank', 'ewallet', 'cash']],
    'category kind' => [CategoryKind::values(), ['income', 'expense']],
    'transaction type' => [TransactionType::values(), ['income', 'expense', 'transfer', 'adjustment']],
    'frequency' => [Frequency::values(), ['weekly', 'monthly', 'yearly']],
]);

it('only categorizes income and expense', function () {
    expect(TransactionType::Income->isCategorized())->toBeTrue()
        ->and(TransactionType::Expense->isCategorized())->toBeTrue()
        ->and(TransactionType::Transfer->isCategorized())->toBeFalse()
        ->and(TransactionType::Adjustment->isCategorized())->toBeFalse();
});
