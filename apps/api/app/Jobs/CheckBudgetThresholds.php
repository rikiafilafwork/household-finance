<?php

namespace App\Jobs;

use App\Models\Budget;
use App\Models\BudgetAlert;
use App\Models\Household;
use App\Notifications\BudgetThresholdReached;
use App\Reports\BudgetSpending;
use App\Support\BudgetMonth;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Keputusan #8: setelah transaksi expense commit, hitung ulang SATU kategori + bulan transaksi
 * itu (termasuk bulan lalu kalau di-backdate). Tiap ambang yang tercapai di-insert ke
 * budget_alerts; UNIQUE (budget_id, threshold) menolak yang kedua → tidak ada spam,
 * juga kalau dua job untuk budget yang sama berjalan bersamaan.
 */
class CheckBudgetThresholds implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $householdId,
        public readonly int $categoryId,
        public readonly string $month, // Y-m-d tanggal 1
    ) {}

    public function handle(): void
    {
        $budget = Budget::query()
            ->with(['category', 'household'])
            ->where('household_id', $this->householdId)
            ->where('category_id', $this->categoryId)
            ->where('month', $this->month)
            ->first();

        if ($budget === null) {
            return;
        }

        $household = $budget->household;
        $spent = BudgetSpending::forCategory($household, BudgetMonth::of($this->month), $this->categoryId);

        $newlyReached = [];
        foreach (BudgetAlert::THRESHOLDS as $threshold) {
            if (! Money::reachesPercent($spent, $budget->limit_amount, $threshold)) {
                continue;
            }

            $inserted = BudgetAlert::query()->insertOrIgnore([
                'budget_id' => $budget->getKey(),
                'threshold' => $threshold,
                'spent_amount' => $spent,
                'triggered_at' => now(),
            ]);

            if ($inserted === 1) {
                $newlyReached[] = $threshold;
            }
        }

        // Lompat dari 50% ke 120% → dua baris alert, tapi cukup satu notifikasi (ambang tertinggi).
        if ($newlyReached !== []) {
            $this->notifyMembers($household, new BudgetThresholdReached($budget, max($newlyReached), $spent));
        }
    }

    /** Budget milik bersama: semua anggota household diberi tahu. */
    private function notifyMembers(Household $household, BudgetThresholdReached $notification): void
    {
        Notification::send($household->members()->get(), $notification);
    }
}
