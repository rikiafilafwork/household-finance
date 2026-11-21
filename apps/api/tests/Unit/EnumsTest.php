<?php

use App\Enums\Frequency;
use App\Enums\HouseholdRole;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WalletType;
use App\Enums\WalletVisibility;

// Nilai enum harus persis sama dengan ENUM di schema Architecture.md.
/**
 * @param  list<string>  $actual
 * @param  list<string>  $expected
 */
it('matches the database schema values', function (array $actual, array $expected) {
    expect($actual)->toBe($expected);
})->with([
    'household role' => [HouseholdRole::values(), ['owner', 'member']],
    'wallet type' => [WalletType::values(), ['cash', 'bank', 'ewallet']],
    'wallet visibility' => [WalletVisibility::values(), ['shared', 'private']],
    'transaction type' => [TransactionType::values(), ['income', 'expense', 'transfer', 'adjustment']],
    'transaction status' => [TransactionStatus::values(), ['posted', 'pending']],
    'frequency' => [Frequency::values(), ['weekly', 'monthly', 'yearly']],
]);

it('only categorizes income and expense', function () {
    expect(TransactionType::Income->isCategorized())->toBeTrue()
        ->and(TransactionType::Expense->isCategorized())->toBeTrue()
        ->and(TransactionType::Transfer->isCategorized())->toBeFalse()
        ->and(TransactionType::Adjustment->isCategorized())->toBeFalse();
});
