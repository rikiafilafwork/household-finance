<?php

namespace App\Reports;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Plan\PlanMonth;
use Illuminate\Database\Eloquent\Builder;

/**
 * Laporan → Tunai vs non-tunai: pengeluaran satu bulan per tipe akun (Keputusan #6, tanpa tabel
 * tambahan). Transaksi diatribusikan ke bulan PERSIS seperti PlanComparisonQuery, jadi total di sini
 * = aktual pengeluaran di Ringkasan/Beranda:
 *  - tidak terhubung ke checklist → bulan tanggal transaksinya;
 *  - terhubung ke checklist → bulan item rencananya (tagihan November dibayar 30 Okt = November),
 *    kecuali itemnya di-skip.
 * Ditambah `unrecorded` per tipe: total penyesuaian saldo bulan itu (dompet tunai paling sering).
 */
final class CashVsNonCashReport
{
    /**
     * @return array{month: string, total: int, types: list<array{type: string, expense: int, percent: int, unrecorded: int}>}
     */
    public function for(User $user, PlanMonth $month): array
    {
        $range = [$month->firstDay(), $month->lastDay()];
        $unlinked = $this->perType(Transaction::ownedBy($user)
            ->where('transactions.type', TransactionType::Expense->value)
            ->whereNull('transactions.plan_item_id')
            ->whereBetween('transactions.occurred_on', $range));

        $linked = $this->perType(Transaction::ownedBy($user)
            ->where('transactions.type', TransactionType::Expense->value)
            ->join('plan_items', 'plan_items.id', '=', 'transactions.plan_item_id')
            ->where('plan_items.month', $month->firstDay())
            ->whereNull('plan_items.skipped_at'));

        $adjustments = $this->perType(Transaction::ownedBy($user)
            ->where('transactions.type', TransactionType::Adjustment->value)
            ->whereBetween('transactions.occurred_on', $range));

        $expenses = [];
        foreach (AccountType::cases() as $type) {
            $expenses[$type->value] = -(($unlinked[$type->value] ?? 0) + ($linked[$type->value] ?? 0));
        }
        $total = array_sum($expenses);

        return [
            'month' => $month->key(),
            'total' => $total,
            'types' => array_map(fn (AccountType $type) => [
                'type' => $type->value,
                'expense' => $expenses[$type->value],
                'percent' => $total > 0 ? intdiv($expenses[$type->value] * 100, $total) : 0,
                'unrecorded' => $adjustments[$type->value] ?? 0,
            ], AccountType::cases()),
        ];
    }

    /**
     * Σ amount per tipe akun.
     *
     * @param  Builder<Transaction>  $query
     * @return array<string, int>
     */
    private function perType(Builder $query): array
    {
        $totals = [];
        foreach ($query->join('accounts', 'accounts.id', '=', 'transactions.account_id')
            ->groupBy('accounts.type')
            ->selectRaw('accounts.type AS account_type, SUM(transactions.amount) AS total')
            ->get() as $row) {
            $totals[(string) $row->getRawOriginal('account_type')] = (int) $row->getRawOriginal('total');
        }

        return $totals;
    }
}
