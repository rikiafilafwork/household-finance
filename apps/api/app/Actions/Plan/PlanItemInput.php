<?php

namespace App\Actions\Plan;

use App\Enums\PlanItemType;
use App\Enums\PlanMode;

/**
 * Data item rencana yang sudah lolos validasi bentuk (FormRequest). Aturan yang butuh DB
 * (kepemilikan kategori/akun, arsip, satu amplop per kategori) dicek di SavePlanItem.
 */
final readonly class PlanItemInput
{
    public function __construct(
        public PlanItemType $type,
        public PlanMode $mode,
        public string $name,
        public int $categoryId,
        public int $plannedAmount,
        public ?int $accountId = null,
        public ?string $dueOn = null,
        public int $sortOrder = 0,
    ) {}
}
