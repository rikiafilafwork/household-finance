<?php

namespace App\Savings;

use App\Actions\Transactions\TransactionInput;
use App\Models\Account;
use App\Models\SavingsAllocation;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Menjaga log alokasi "Gunakan tabungan" tetap sinkron saat transaksinya diedit/dihapus lewat
 * halaman Transaksi. Dipanggil di dalam transaksi DB Action transaksi, SETELAH LockAccounts:
 * rekening transaksi = rekening tujuan (pindah rekening ditolak), jadi urutan kunci rekening →
 * tujuan tetap terjaga. Tujuan dikunci di sini.
 */
final class LinkedAllocation
{
    public static function assertUpdatable(Transaction $current, TransactionInput $input): void
    {
        $locked = self::locked($current);
        if ($locked === null) {
            return;
        }
        [$allocation, $goal] = $locked;

        $message = 'Transaksi ini memakai tabungan "'.$goal->name.'". Hapus transaksinya untuk mengembalikan alokasi.';
        if ($input->accountId !== $current->account_id) {
            throw ValidationException::withMessages(['account_id' => $message]);
        }
        if ($input->type !== $current->type) {
            throw ValidationException::withMessages(['type' => $message]);
        }

        // Log = nominal transaksi (negatif), jadi maksimum yang boleh dipakai = alokasi + |nominal lama|.
        $available = $goal->allocated_amount - $allocation->amount;
        if (abs($input->signedAmount()) > $available) {
            throw ValidationException::withMessages([
                'amount' => 'Melebihi alokasi tujuan "'.$goal->name.'" ('.Money::format($available).')',
            ]);
        }
    }

    /** $current sudah berisi nominal baru. */
    public static function afterUpdate(Transaction $current): void
    {
        $locked = self::locked($current);
        if ($locked === null) {
            return;
        }
        [$allocation, $goal] = $locked;

        $delta = $current->amount - $allocation->amount;
        if ($delta === 0) {
            return;
        }

        $allocation->update(['amount' => $current->amount]);
        $goal->allocated_amount += $delta;
        // Nominal turun = uang kembali ke tujuan; tujuan arsip tidak boleh memegang uang → buka lagi.
        if ($delta > 0) {
            $goal->archived_at = null;
        }
        $goal->save();
    }

    /**
     * Uang kembali ke tujuannya; tujuan arsip dibuka lagi karena kini memegang uang. Ditolak kalau
     * rekeningnya sudah diarsip / bukan rekening tabungan lagi: tujuan tidak boleh memegang uang di sana.
     */
    public static function afterDelete(Transaction $current, Account $account): void
    {
        $locked = self::locked($current);
        if ($locked === null) {
            return;
        }
        [$allocation, $goal] = $locked;

        if (! $account->is_savings || $account->isArchived()) {
            throw ValidationException::withMessages([
                'transaction' => 'Transaksi ini memakai tabungan dari rekening yang sudah diarsip atau bukan rekening tabungan lagi. Aktifkan kembali rekeningnya dulu.',
            ]);
        }

        $allocation->delete();
        $goal->allocated_amount -= $allocation->amount;
        $goal->archived_at = null;
        $goal->save();
    }

    /**
     * @return array{0: SavingsAllocation, 1: SavingsGoal}|null
     */
    private static function locked(Transaction $current): ?array
    {
        $allocation = SavingsAllocation::ownedBy($current->user_id)->where('transaction_id', $current->getKey())->first();
        if ($allocation === null) {
            return null;
        }

        /** @var SavingsGoal $goal */
        $goal = SavingsGoal::ownedBy($current->user_id)->whereKey($allocation->savings_goal_id)->lockForUpdate()->firstOrFail();

        return [$allocation, $goal];
    }
}
