<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Concerns\BelongsToUser;
use App\Models\Import;
use App\Models\Transaction;

/*
 * Penjaga isolasi data (Keputusan #5): global scope BelongsToUser hanya boleh dilewati di file
 * yang ada di daftar ini, masing-masing dengan alasan. Kode baru yang memakai
 * withoutGlobalScope(s), DB::table() atau toBase() akan membuat test ini gagal: pakai model
 * ber-scope / Model::ownedBy($user), atau tambahkan ke daftar dengan alasan + test isolasi.
 */

const SCOPE_BYPASS_ALLOWLIST = [
    'Models/Concerns/BelongsToUser.php' => 'definisi ownedBy(): melepas scope lalu WHERE user_id = user eksplisit',
    'Models/User.php' => 'relasi accounts/categories/transactions sudah dibatasi FK user_id',
    'Actions/Accounts/ArchiveAccount.php' => 'exists() termasuk soft-deleted pada akun yang sudah ter-scope',
    'Actions/Accounts/ReconcileAccounts.php' => 'internal (command/scheduler) lintas user; notifikasi per pemilik',
    'Actions/Categories/DeleteCategory.php' => 'exists() termasuk soft-deleted, tidak mengembalikan data',
    'Support/DefaultCategories.php' => 'insertOrIgnore kategori bawaan untuk satu user eksplisit',
    'Console/Commands/ResetUserPassword.php' => 'hapus baris sessions milik satu user (bukan data keuangan)',
    'Console/Commands/DemoUserCommand.php' => 'demo:user --empty: hapus data milik satu user demo eksplisit (WHERE user_id)',
];

it('only bypasses the user scope in allow-listed files', function () {
    $pattern = '/withoutGlobalScopes?\(|DB::table\(|->toBase\(\)|DB::select\(/';

    $root = dirname(__DIR__, 3).'/app/';
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root));
        if (array_key_exists($relative, SCOPE_BYPASS_ALLOWLIST)) {
            continue;
        }

        if (preg_match($pattern, (string) file_get_contents($file->getPathname()), $m)) {
            $offenders[] = "{$relative} ({$m[0]})";
        }
    }

    expect($offenders)->toBe([], 'Scope user dilewati di luar allowlist: '.implode(', ', $offenders));
});

it('keeps the allowlist honest (every entry still exists and still bypasses the scope)', function () {
    $root = dirname(__DIR__, 3).'/app/';

    foreach (array_keys(SCOPE_BYPASS_ALLOWLIST) as $relative) {
        expect(file_exists($root.$relative))->toBeTrue("{$relative} sudah tidak ada; hapus dari allowlist")
            ->and((string) file_get_contents($root.$relative))
            ->toMatch('/withoutGlobalScopes?\(|DB::table\(|->toBase\(\)/', "{$relative} tidak lagi melewati scope; hapus dari allowlist");
    }
});

it('makes every user-owned model use BelongsToUser', function (string $model) {
    expect(class_uses_recursive($model))->toHaveKey(BelongsToUser::class);
})->with([
    Account::class,
    Category::class,
    CategoryRule::class,
    Import::class,
    Transaction::class,
]);

it('makes TransactionQuery scope by an explicit user', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Reports/TransactionQuery.php');

    expect($source)->toContain('Transaction::ownedBy($user)');
});
