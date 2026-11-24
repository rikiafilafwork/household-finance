<?php

namespace App\Http\Requests\Plan;

use App\Actions\Plan\PlanTemplateInput;
use App\Enums\Frequency;
use App\Enums\PlanItemType;
use App\Enums\PlanMode;
use App\Http\Requests\Accounts\StoreAccountRequest;
use App\Models\PlanTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tambah (POST: type, mode, name, category_id, planned_amount wajib) atau ubah (PATCH, parsial:
 * field yang tidak dikirim memakai nilai template). Aturan antar-field di SavePlanTemplate.
 */
class PlanTemplateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'type' => [$required, Rule::enum(PlanItemType::class)],
            'mode' => [$required, Rule::enum(PlanMode::class)],
            'name' => [$required, 'string', 'max:100'],
            'category_id' => [$required, 'integer'],
            'planned_amount' => [$required, 'integer:strict', 'min:1', 'max:'.StoreAccountRequest::MAX_AMOUNT],
            'account_id' => ['sometimes', 'nullable', 'integer'],
            'frequency' => ['sometimes', Rule::in([Frequency::Monthly->value, Frequency::Yearly->value])],
            'day_of_month' => ['sometimes', 'nullable', 'integer:strict', 'between:1,31'],
            'month_of_year' => ['sometimes', 'nullable', 'integer:strict', 'between:1,12'],
            'auto_post' => ['sometimes', 'boolean:strict'],
            'is_active' => ['sometimes', 'boolean:strict'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function toInput(?PlanTemplate $current = null): PlanTemplateInput
    {
        $pick = fn (string $key, mixed $fallback) => $this->has($key) ? $this->input($key) : $fallback;

        $mode = $this->has('mode') ? PlanMode::from($this->string('mode')->toString()) : $current->mode ?? PlanMode::Checklist;
        $frequency = $this->has('frequency') ? Frequency::from($this->string('frequency')->toString()) : $current->frequency ?? Frequency::Monthly;
        // Pindah ke amplop / ke bulanan tanpa mengirim field terkait: nilai lama yang tidak berlaku dibuang.
        $day = $pick('day_of_month', $mode === PlanMode::Envelope ? null : $current?->day_of_month);
        $monthOfYear = $pick('month_of_year', $frequency === Frequency::Monthly ? null : $current?->month_of_year);
        $autoPost = $pick('auto_post', $mode === PlanMode::Envelope ? false : ($current->auto_post ?? false));
        $account = $pick('account_id', $current?->account_id);

        return new PlanTemplateInput(
            type: $this->has('type') ? PlanItemType::from($this->string('type')->toString()) : $current->type ?? PlanItemType::Expense,
            mode: $mode,
            name: (string) $pick('name', $current?->name),
            categoryId: (int) $pick('category_id', $current?->category_id),
            plannedAmount: (int) $pick('planned_amount', $current?->planned_amount),
            accountId: $account !== null ? (int) $account : null,
            frequency: $frequency,
            dayOfMonth: $day !== null ? (int) $day : null,
            monthOfYear: $monthOfYear !== null ? (int) $monthOfYear : null,
            autoPost: (bool) $autoPost,
            isActive: (bool) $pick('is_active', $current->is_active ?? true),
        );
    }
}
