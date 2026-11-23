<?php

namespace App\Plan;

use App\Enums\PlanItemType;
use App\Enums\PlanMode;
use App\Enums\TransactionType;
use App\Jobs\CheckEnvelopeThresholds;
use App\Models\PlanItem;
use App\Models\Transaction;

/**
 * Kapan CheckEnvelopeThresholds perlu jalan. Job di-dispatch setelah commit (ShouldQueueAfterCommit).
 */
final class EnvelopeCheck
{
    /** Pengeluaran berkategori yang tidak terhubung ke checklist (yang terhubung tidak masuk amplop). */
    public static function afterTransaction(Transaction $transaction): void
    {
        if ($transaction->type !== TransactionType::Expense
            || $transaction->category_id === null
            || $transaction->plan_item_id !== null) {
            return;
        }

        CheckEnvelopeThresholds::dispatch(
            $transaction->user_id,
            $transaction->category_id,
            PlanMonth::fromDate($transaction->occurred_on)->firstDay(),
        );
    }

    /** Amplop baru / diubah (mis. nominal diturunkan) bisa langsung melewati ambang. */
    public static function afterEnvelopeSaved(PlanItem $item): void
    {
        if ($item->mode !== PlanMode::Envelope || $item->type !== PlanItemType::Expense || $item->skipped_at !== null) {
            return;
        }

        CheckEnvelopeThresholds::dispatch($item->user_id, $item->category_id, PlanMonth::fromDate($item->month)->firstDay());
    }
}
