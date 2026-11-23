<?php

namespace App\Jobs;

use App\Enums\PlanItemType;
use App\Enums\PlanMode;
use App\Models\BudgetAlert;
use App\Models\PlanItem;
use App\Models\User;
use App\Notifications\EnvelopeThresholdReached;
use App\Queries\PlanComparisonQuery;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Keputusan #10: setelah transaksi pengeluaran commit, hitung ulang SATU amplop (kategori + bulan
 * transaksi itu, termasuk bulan lalu kalau di-backdate). Tiap ambang yang tercapai di-insert ke
 * budget_alerts; UNIQUE (plan_item_id, threshold) menolak yang kedua → tidak ada spam.
 * Hanya amplop pengeluaran yang tidak di-skip; checklist tidak punya alert (nominalnya tetap).
 */
class CheckEnvelopeThresholds implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $userId,
        public readonly int $categoryId,
        public readonly string $month, // Y-m-d tanggal 1
    ) {}

    public function handle(PlanComparisonQuery $query): void
    {
        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }

        $envelope = PlanItem::ownedBy($user)
            ->forMonth($this->month)
            ->where('mode', PlanMode::Envelope->value)
            ->where('type', PlanItemType::Expense->value)
            ->where('category_id', $this->categoryId)
            ->whereNull('skipped_at')
            ->first();

        if ($envelope === null) {
            return;
        }

        $spent = $query->envelopeActual($envelope);

        $newlyReached = [];
        foreach (BudgetAlert::THRESHOLDS as $threshold) {
            if (! Money::reachesPercent($spent, $envelope->planned_amount, $threshold)) {
                continue;
            }

            $inserted = BudgetAlert::query()->insertOrIgnore([
                'plan_item_id' => $envelope->getKey(),
                'threshold' => $threshold,
                'spent_amount' => $spent,
                'triggered_at' => now(),
            ]);

            if ($inserted === 1) {
                $newlyReached[] = $threshold;
            }
        }

        // Lompat dari 50% ke 120% → dua baris alert, cukup satu notifikasi (ambang tertinggi).
        if ($newlyReached !== []) {
            $user->notify(new EnvelopeThresholdReached($envelope, max($newlyReached), $spent));
        }
    }
}
