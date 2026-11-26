<?php

namespace App\Queries;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Laporan → Cash flow: net harian + saldo berjalan untuk semua akun (atau satu akun), berbasis
 * TANGGAL transaksi karena ini pergerakan uang sungguhan. Semua tipe dihitung (transfer antar akun
 * sendiri saling meniadakan di mode semua akun; penyesuaian saldo ikut karena mengubah saldo).
 *
 * Saldo awal = Σ opening_balance akun (termasuk yang diarsip) + Σ transaksi sebelum `from`, jadi
 * saldo hari terakhir = saldo akun (accounts.balance) kalau `to` ≥ transaksi terakhir. Saldo
 * berjalan dihitung MySQL dengan window function `SUM(SUM(amount)) OVER (ORDER BY occurred_on)`;
 * hari tanpa transaksi diisi net 0 supaya grafik tidak bolong.
 */
final class CashFlowQuery
{
    public const MAX_DAYS = 366;

    /**
     * @return array{from: string, to: string, account_id: int|null, opening_balance: int, closing_balance: int, days: list<array{date: string, net: int, balance: int}>}
     */
    public function for(User $user, string $from, string $to, ?Account $account = null): array
    {
        $accounts = Account::ownedBy($user)->when($account, fn ($q) => $q->whereKey($account?->getKey()));
        $transactions = fn () => Transaction::ownedBy($user)->when($account, fn ($q) => $q->where('account_id', $account?->getKey()));

        $opening = (int) $accounts->sum('opening_balance') + (int) $transactions()->where('occurred_on', '<', $from)->sum('amount');

        /** @var array<string, array{net: int, cumulative: int}> $byDay */
        $byDay = $transactions()
            ->whereBetween('occurred_on', [$from, $to])
            ->groupBy('occurred_on')
            ->orderBy('occurred_on')
            ->selectRaw('occurred_on, SUM(amount) AS net, SUM(SUM(amount)) OVER (ORDER BY occurred_on) AS cumulative')
            ->get()
            ->mapWithKeys(fn (Transaction $r) => [(string) $r->getRawOriginal('occurred_on') => [
                'net' => (int) $r->getRawOriginal('net'),
                'cumulative' => (int) $r->getRawOriginal('cumulative'),
            ]])
            ->all();

        $days = [];
        $balance = $opening;
        for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
            $date = $day->toDateString();
            $row = $byDay[$date] ?? null;
            if ($row !== null) {
                $balance = $opening + $row['cumulative'];
            }
            $days[] = ['date' => $date, 'net' => $row['net'] ?? 0, 'balance' => $balance];
        }

        return [
            'from' => $from,
            'to' => $to,
            'account_id' => $account === null ? null : (int) $account->getKey(),
            'opening_balance' => $opening,
            'closing_balance' => $balance,
            'days' => $days,
        ];
    }
}
