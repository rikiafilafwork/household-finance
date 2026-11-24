<?php

namespace App\Actions\Plan;

use App\Enums\Frequency;
use App\Enums\PlanItemType;
use App\Enums\PlanMode;

/**
 * Data template yang sudah lolos validasi bentuk (FormRequest). Aturan antar-field dan yang butuh DB
 * dicek di SavePlanTemplate.
 */
final readonly class PlanTemplateInput
{
    public function __construct(
        public PlanItemType $type,
        public PlanMode $mode,
        public string $name,
        public int $categoryId,
        public int $plannedAmount,
        public ?int $accountId = null,
        public Frequency $frequency = Frequency::Monthly,
        public ?int $dayOfMonth = null,
        public ?int $monthOfYear = null,
        public bool $autoPost = false,
        public bool $isActive = true,
    ) {}
}
