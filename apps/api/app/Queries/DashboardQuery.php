<?php

namespace App\Queries;

use App\Actions\Accounts\ReconcileAccounts;
use App\Enums\AccountType;
use App\Enums\PlanItemType;
use App\Enums\PlanMode;
use App\Models\Account;
use App\Models\Category;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use App\Plan\PlanMonth;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Beranda (Keputusan #11): satu endpoint, satu query class. Semua angka rencana vs aktual berasal
 * dari PlanComparisonQuery — sama persis dengan halaman Rencana dan Laporan; di sini hanya disusun.
 * Tanpa cache; beberapa query kecil ber-index (bukti ukur di Minggu 7).
 *
 *  - Saldo & uang bebas = keadaan HARI INI (akun aktif); uang bebas hanya untuk bulan berjalan.
 *  - Perlu dicentang = checklist belum dicentang/di-skip, jatuh tempo ≤ 7 hari dari hari ini atau
 *    sudah lewat (zona waktu user).
 *  - Tren = 6 bulan berakhir di bulan yang diminta, tiap bulan lewat PlanComparisonQuery.
 */
final class DashboardQuery
{
    public const DUE_SOON_DAYS = 7;

    public const TREND_MONTHS = 6;

    public const RECENT = 5;

    public const TOP_GOALS = 3;

    public function __construct(
        private readonly PlanComparisonQuery $plans,
        private readonly ReconcileAccounts $reconcile,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user, PlanMonth $month, ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone($user->timezone)->toDateString();
        $isCurrent = substr($today, 0, 7) === $month->key();
        $plan = $this->plans->for($user, $month);

        $balances = $this->balances($user);
        $expense = $plan->totals['expense'];

        return [
            'month' => $month->key(),
            'is_current_month' => $isCurrent,
            'balances' => $balances,
            'income' => $plan->totals['income'],
            'expense' => $expense,
            'remaining_budget' => $plan->remainingBudget(),
            'still_to_spend' => $plan->stillToSpend(),
            // Saldo hari ini di luar rekening tabungan − yang masih akan keluar bulan ini; bulan lain
            // tidak bermakna → null. Tabungan sudah "dipegang" tujuan, jadi bukan uang bebas.
            'free_money' => $isCurrent ? $balances['total'] - $balances['savings'] - $plan->stillToSpend() : null,
            'savings' => $this->savings($user, $now),
            'categories' => $this->categories($user, $plan),
            'income_sources' => $this->incomeSources($plan),
            'due_items' => $this->dueItems($plan, $today),
            'envelopes_warning' => $this->envelopesWarning($plan),
            'trend' => $this->trend($user, $month, $plan),
            'recent' => $this->recent($user),
            'reconcile_ok' => $this->reconcile->mismatches($user->getKey())->isEmpty(),
        ];
    }

    /**
     * `total` = semua akun aktif (termasuk tabungan); `savings` = bagian dari total yang di rekening tabungan.
     *
     * @return array{bank: int, ewallet: int, cash: int, total: int, savings: int}
     */
    private function balances(User $user): array
    {
        $active = Account::ownedBy($user)->whereNull('archived_at');
        $sums = (clone $active)->groupBy('type')->selectRaw('type, SUM(balance) AS total')->pluck('total', 'type');

        $out = [];
        foreach (AccountType::cases() as $type) {
            $out[$type->value] = (int) ($sums[$type->value] ?? 0);
        }
        $out['total'] = array_sum($out);
        $out['savings'] = (int) $active->where('is_savings', true)->sum('balance');

        /** @var array{bank: int, ewallet: int, cash: int, total: int, savings: int} */
        return $out;
    }

    /**
     * Ringkasan tabungan dari SavingsQuery (angka sama dengan halaman Tabungan) + maksimal 3 tujuan
     * belum tercapai dengan persen tertinggi.
     *
     * @return array{balance: int, allocated: int, unallocated: int, overdrawn: int, goals: list<array{id: int, name: string, allocated_amount: int, target_amount: int, percent: int}>}
     */
    private function savings(User $user, ?CarbonImmutable $now): array
    {
        $summary = SavingsQuery::for($user, $now);

        $goals = collect($summary['accounts'])
            ->flatMap(fn (array $row) => $row['goals']->all())
            ->filter(fn (SavingsGoal $g) => $g->achieved_at === null && $g->allocated_amount < $g->target_amount)
            ->map(fn (SavingsGoal $g) => [
                'id' => (int) $g->getKey(),
                'name' => $g->name,
                'allocated_amount' => $g->allocated_amount,
                'target_amount' => $g->target_amount,
                'percent' => Money::percentOf($g->allocated_amount, $g->target_amount),
            ])
            ->sortByDesc('percent')
            ->take(self::TOP_GOALS)
            ->values()->all();

        return $summary['totals'] + ['goals' => $goals];
    }

    /**
     * Pengeluaran per kategori (untuk chart): baris PlanComparison bertipe expense + warna kategori.
     *
     * @return list<array{id: int|null, name: string, color: string|null, planned: int, actual: int}>
     */
    private function categories(User $user, PlanComparison $plan): array
    {
        $rows = array_values(array_filter($plan->categories, fn (array $r) => $r['type'] === PlanItemType::Expense->value));
        /** @var Collection<int, Category> $cats */
        $cats = Category::ownedBy($user)->whereKey(array_filter(array_column($rows, 'category_id')))->get()->keyBy('id');

        return array_map(fn (array $r) => [
            'id' => $r['category_id'],
            'name' => $r['name'],
            'color' => $r['category_id'] !== null ? $cats->get($r['category_id'])?->color : null,
            'planned' => $r['planned'],
            'actual' => $r['actual'],
        ], $rows);
    }

    /**
     * @return list<array{id: int, name: string, planned: int, actual: int, done: bool, skipped: bool}>
     */
    private function incomeSources(PlanComparison $plan): array
    {
        return $plan->items
            ->filter(fn (PlanItemComparison $r) => $r->item->type === PlanItemType::Income)
            ->map(fn (PlanItemComparison $r) => [
                'id' => (int) $r->item->getKey(),
                'name' => $r->item->name,
                'planned' => $r->item->planned_amount,
                'actual' => $r->actual,
                // Checklist: sudah dicentang. Amplop pemasukan: aktual sudah mencapai rencana.
                'done' => $r->item->mode === PlanMode::Checklist ? $r->status() === 'done' : $r->actual >= $r->item->planned_amount,
                'skipped' => $r->status() === 'skipped',
            ])
            ->values()->all();
    }

    /**
     * @return list<array{id: int, type: string, name: string, due_on: string, planned: int, account_id: int|null, overdue: bool}>
     */
    private function dueItems(PlanComparison $plan, string $today): array
    {
        $limit = CarbonImmutable::parse($today)->addDays(self::DUE_SOON_DAYS)->toDateString();

        return $plan->items
            ->filter(fn (PlanItemComparison $r) => $r->item->mode === PlanMode::Checklist
                && $r->status() === 'planned'
                && $r->item->due_on !== null
                && $r->item->due_on->toDateString() <= $limit)
            ->sortBy(fn (PlanItemComparison $r) => [$r->item->due_on?->toDateString(), $r->item->getKey()])
            ->map(fn (PlanItemComparison $r) => [
                'id' => (int) $r->item->getKey(),
                'type' => $r->item->type->value,
                'name' => $r->item->name,
                'due_on' => (string) $r->item->due_on?->toDateString(),
                'planned' => $r->item->planned_amount,
                'account_id' => $r->item->account_id,
                'overdue' => (string) $r->item->due_on?->toDateString() < $today,
            ])
            ->values()->all();
    }

    /**
     * @return list<array{id: int, name: string, planned: int, actual: int, percent: int}>
     */
    private function envelopesWarning(PlanComparison $plan): array
    {
        return $plan->items
            ->filter(fn (PlanItemComparison $r) => $r->item->mode === PlanMode::Envelope
                && $r->item->type === PlanItemType::Expense
                && $r->status() !== 'skipped'
                && ($r->percent() ?? 0) >= 80)
            ->sortByDesc(fn (PlanItemComparison $r) => $r->percent())
            ->map(fn (PlanItemComparison $r) => [
                'id' => (int) $r->item->getKey(),
                'name' => $r->item->name,
                'planned' => $r->item->planned_amount,
                'actual' => $r->actual,
                'percent' => (int) $r->percent(),
            ])
            ->values()->all();
    }

    /**
     * @return list<array{month: string, income_actual: int, expense_actual: int, expense_planned: int}>
     */
    private function trend(User $user, PlanMonth $month, PlanComparison $current): array
    {
        $months = [$month];
        for ($i = 1; $i < self::TREND_MONTHS; $i++) {
            $months[] = end($months)->previous();
        }

        return array_map(function (PlanMonth $m) use ($user, $month, $current) {
            $plan = $m->key() === $month->key() ? $current : $this->plans->for($user, $m);

            return [
                'month' => $m->key(),
                'income_actual' => $plan->totals['income']['actual'],
                'expense_actual' => $plan->totals['expense']['actual'],
                'expense_planned' => $plan->totals['expense']['planned'],
            ];
        }, array_reverse($months));
    }

    /**
     * @return list<array{id: int, type: string, account_id: int, category_id: int|null, amount: int, occurred_on: string, note: string|null, plan_item_id: int|null}>
     */
    private function recent(User $user): array
    {
        return Transaction::ownedBy($user)
            ->orderByDesc('occurred_on')->orderByDesc('id')
            ->limit(self::RECENT)
            ->get()
            ->map(fn (Transaction $t) => [
                'id' => (int) $t->getKey(),
                'type' => $t->type->value,
                'account_id' => $t->account_id,
                'category_id' => $t->category_id,
                'amount' => $t->amount,
                'occurred_on' => $t->occurred_on->toDateString(),
                'note' => $t->note,
                'plan_item_id' => $t->plan_item_id,
            ])
            ->values()->all();
    }
}
