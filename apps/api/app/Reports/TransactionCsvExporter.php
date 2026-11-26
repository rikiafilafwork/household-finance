<?php

namespace App\Reports;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Generator;

/**
 * Export transaksi ke CSV tanpa memuat semuanya ke memori (Minggu 7).
 *
 *  - Dibaca per halaman keyset `(occurred_on, id)` memakai idx_tx_user_date, jadi urut tanggal dan
 *    memori datar berapa pun jumlah barisnya (`lazyById` hanya bisa urut id; OFFSET makin lambat).
 *  - UTF-8 dengan BOM supaya Excel membaca huruf non-ASCII; delimiter `;` (default, Excel locale
 *    Indonesia), `,`, atau tab.
 *  - Nominal integer rupiah ber-tanda tanpa pemisah ribuan, supaya terbaca sebagai angka.
 *  - Teks buatan user (catatan, nama akun/kategori) yang diawali `= + - @` atau tab/CR diberi
 *    awalan `'` supaya tidak dijalankan sebagai rumus (CSV/formula injection).
 *  - Transaksi milik satu user saja (Transaction::ownedBy), soft-deleted tidak ikut.
 */
final class TransactionCsvExporter
{
    public const DELIMITERS = [';', ',', "\t"];

    public const HEADER = ['tanggal', 'akun', 'jenis_akun', 'tipe', 'kategori', 'nominal', 'catatan', 'transfer_id', 'id'];

    private const PAGE = 1_000;

    private const TYPE_LABEL = [
        'income' => 'pemasukan',
        'expense' => 'pengeluaran',
        'transfer' => 'transfer',
        'adjustment' => 'penyesuaian',
    ];

    /**
     * Baris CSV (sudah berakhiran \n), diawali BOM + header.
     *
     * @return Generator<int, string>
     */
    public function lines(User $user, string $from, string $to, string $delimiter = ';'): Generator
    {
        $accounts = Account::ownedBy($user)->get(['id', 'name', 'type'])->keyBy('id');
        $categories = Category::ownedBy($user)->pluck('name', 'id');

        yield "\u{FEFF}".$this->row(self::HEADER, $delimiter);

        $after = null;
        do {
            $page = Transaction::ownedBy($user)
                ->whereBetween('occurred_on', [$from, $to])
                ->when($after, fn ($q) => $q->where(fn ($w) => $w
                    ->where('occurred_on', '>', $after[0])
                    ->orWhere(fn ($same) => $same->where('occurred_on', $after[0])->where('id', '>', $after[1]))))
                ->orderBy('occurred_on')->orderBy('id')
                ->limit(self::PAGE)
                ->get(['id', 'account_id', 'category_id', 'type', 'amount', 'occurred_on', 'note', 'transfer_group_id']);

            foreach ($page as $tx) {
                $account = $accounts->get($tx->account_id);
                yield $this->row([
                    $tx->occurred_on->toDateString(),
                    self::text($account->name ?? ''),
                    $account?->type->value ?? '',
                    self::TYPE_LABEL[$tx->type->value],
                    self::text($tx->category_id === null ? '' : (string) $categories->get($tx->category_id, '')),
                    (string) $tx->amount,
                    self::text((string) $tx->note),
                    (string) $tx->transfer_group_id,
                    (string) $tx->id,
                ], $delimiter);
            }

            $last = $page->last();
            $after = $last === null ? null : [$last->occurred_on->toDateString(), $last->id];
        } while ($page->count() === self::PAGE);
    }

    /** Nama file unduhan, mis. `transaksi-2026-11-01_2026-11-30.csv`. */
    public static function filename(string $from, string $to): string
    {
        return "transaksi-{$from}_{$to}.csv";
    }

    /** Sel teks yang bisa dibaca sebagai rumus oleh Excel/Sheets diberi awalan `'`. */
    public static function text(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /** @param  list<string>  $cells */
    private function row(array $cells, string $delimiter): string
    {
        return implode($delimiter, array_map(function (string $cell) use ($delimiter) {
            $needsQuotes = $cell !== '' && (str_contains($cell, $delimiter) || strpbrk($cell, "\"\r\n") !== false);

            return $needsQuotes ? '"'.str_replace('"', '""', $cell).'"' : $cell;
        }, $cells))."\n";
    }
}
