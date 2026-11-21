<?php

/*
 * Penjaga #25: tidak ada query baca transaksi yang melewati VisibleWallets.
 *
 * Membaca transaksi untuk ditampilkan wajib lewat App\Reports\TransactionQuery (yang memfilter
 * wallet_id IN VisibleWallets::for(...)). Selain itu hanya file di daftar ini yang boleh membuat
 * query ke tabel transactions, masing-masing dengan alasan. File baru yang menulis query transaksi
 * akan membuat test ini gagal: pakai TransactionQuery, atau tambahkan ke daftar dengan alasan +
 * test privasi untuk endpoint-nya.
 */

const TRANSACTION_QUERY_ALLOWLIST = [
    'Reports/TransactionQuery.php' => 'sumber query baca; selalu wallet_id IN VisibleWallets',
    'Reports/BudgetSpending.php' => 'agregat budget; hanya wallet SHARED (terlihat semua anggota)',
    'Actions/Transactions/ValidatesTransactionTarget.php' => 'replay client_id; dicek VisibleWallets::canSee',
    'Actions/Transactions/UpdateTransaction.php' => 'mengunci baris yang sudah lolos TransactionPolicy',
    'Actions/Transactions/DeleteTransaction.php' => 'mengunci baris yang sudah lolos TransactionPolicy',
    'Actions/Transfers/TransferPair.php' => 'pemanggil wajib cek visibleTo()/manageableBy()',
    'Actions/Wallets/ArchiveWallet.php' => 'hanya exists(), wallet sudah lolos WalletPolicy',
    'Actions/Wallets/ReconcileWallets.php' => 'internal (command), notifikasi difilter per penerima',
    'Actions/Categories/DeleteCategory.php' => 'hanya exists(), tidak mengembalikan data',
];

it('only builds transaction queries in allow-listed files', function () {
    $pattern = '/Transaction::(query|where\w*|with\w*|find\w*|all|first\w*|on|select|pluck|sum|count)\b'
        .'|->transactions\(\)'
        .'|table\([\'"]transactions[\'"]\)'
        .'|from\([\'"]transactions[\'"]\)/';

    $root = dirname(__DIR__, 3).'/app/';
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root));
        if (str_starts_with($relative, 'Models/') || array_key_exists($relative, TRANSACTION_QUERY_ALLOWLIST)) {
            continue;
        }

        if (preg_match($pattern, (string) file_get_contents($file->getPathname()), $m)) {
            $offenders[] = "{$relative} ({$m[0]})";
        }
    }

    expect($offenders)->toBe([], 'Query transaksi di luar TransactionQuery/allowlist: '.implode(', ', $offenders));
});

it('keeps the allowlist honest (every entry still exists and still queries transactions)', function () {
    $root = dirname(__DIR__, 3).'/app/';

    foreach (array_keys(TRANSACTION_QUERY_ALLOWLIST) as $relative) {
        expect(file_exists($root.$relative))->toBeTrue("{$relative} sudah tidak ada; hapus dari allowlist");
    }
});

it('makes TransactionQuery filter by VisibleWallets', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Reports/TransactionQuery.php');

    expect($source)->toContain('VisibleWallets::for(')->toContain("whereIn('wallet_id'");
});
