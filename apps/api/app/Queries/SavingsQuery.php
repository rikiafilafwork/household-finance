<?php

namespace App\Queries;

use App\Models\Account;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Savings\MonthlyNeeded;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Satu-satunya tempat angka tabungan dihitung (halaman Tabungan dan Beranda memakai ini).
 * `allocated` = Σ alokasi SEMUA tujuan di rekening itu; `unallocated` boleh negatif;
 * `overdrawn` = tabungan yang sudah terpakai (alokasi melebihi saldo), dihitung saat dibaca.
 */
class SavingsQuery
{
    /**
     * @return array{
     *     accounts: list<array{account: Account, balance: int, allocated: int, unallocated: int, overdrawn: int, goals: Collection<int, SavingsGoal>}>,
     *     totals: array{balance: int, allocated: int, unallocated: int, overdrawn: int}
     * }
     */
    public static function for(User $user, ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone($user->timezone)->toDateString();

        $accounts = Account::ownedBy($user)
            ->where('is_savings', true)
            ->whereNull('archived_at')
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        $goals = SavingsGoal::ownedBy($user)
            ->whereIn('account_id', $accounts->modelKeys())
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->groupBy('account_id');

        $rows = [];
        $totals = ['balance' => 0, 'allocated' => 0, 'unallocated' => 0, 'overdrawn' => 0];

        foreach ($accounts as $account) {
            /** @var Collection<int, SavingsGoal> $accountGoals */
            $accountGoals = $goals->get($account->getKey(), new Collection);

            $allocated = (int) $accountGoals->sum('allocated_amount');
            $balance = $account->balance;
            $row = [
                'account' => $account,
                'balance' => $balance,
                'allocated' => $allocated,
                'unallocated' => $balance - $allocated,
                // Dibatasi ke alokasi: saldo minus tanpa tujuan bukan "tabungan terpakai" (tidak ada uang tujuan yang hilang).
                'overdrawn' => min($allocated, max(0, $allocated - $balance)),
                // Tujuan arsip tidak pernah memegang uang, jadi cukup disembunyikan dari daftar.
                'goals' => $accountGoals->whereNull('archived_at')->values()->each(
                    fn (SavingsGoal $goal) => $goal->setAttribute('monthly_needed', MonthlyNeeded::for(
                        $goal->target_amount, $goal->allocated_amount, $goal->target_date?->toDateString(), $today,
                    )),
                ),
            ];

            $rows[] = $row;
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $row[$key];
            }
        }

        return ['accounts' => $rows, 'totals' => $totals];
    }
}
