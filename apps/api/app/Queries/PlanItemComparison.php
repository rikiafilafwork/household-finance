<?php

namespace App\Queries;

use App\Enums\PlanMode;
use App\Models\PlanItem;
use App\Models\Transaction;

final readonly class PlanItemComparison
{
    public function __construct(
        public PlanItem $item,
        public int $actual,               // positif
        public ?Transaction $transaction, // transaksi centang (checklist)
    ) {}

    public function status(): string
    {
        return $this->transaction !== null ? 'done' : ($this->item->skipped_at !== null ? 'skipped' : 'planned');
    }

    /** Persen terpakai amplop, dibulatkan ke bawah, tanpa float (Keputusan #1). */
    public function percent(): ?int
    {
        if ($this->item->mode !== PlanMode::Envelope || $this->item->planned_amount <= 0) {
            return null;
        }

        return intdiv($this->actual * 100, $this->item->planned_amount);
    }
}
