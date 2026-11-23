<?php

namespace App\Http\Requests\Plan;

use App\Actions\Plan\PlanItemInput;
use App\Enums\PlanItemType;
use App\Enums\PlanMode;
use App\Http\Requests\Accounts\StoreAccountRequest;
use App\Models\PlanItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tambah (POST, semua wajib) atau ubah (PATCH, parsial: field yang tidak dikirim memakai nilai item).
 * `planned_amount` = integer rupiah positif (Keputusan #1).
 */
class PlanItemRequest extends FormRequest
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
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sort_order' => ['sometimes', 'integer:strict', 'between:0,32767'],
            // Diturunkan / dijaga server, tidak dikirim klien.
            'month' => ['prohibited'],
            'skipped_at' => ['prohibited'],
            'source' => ['prohibited'],
            'template_id' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function toInput(?PlanItem $current = null): PlanItemInput
    {
        $pick = fn (string $key, mixed $fallback) => $this->has($key) ? $this->input($key) : $fallback;
        $mode = $this->has('mode') ? PlanMode::from($this->string('mode')->toString()) : $current->mode ?? PlanMode::Checklist;
        // Pindah ke amplop tanpa mengirim due_on: jatuh tempo lama dibuang (amplop tidak punya jatuh tempo).
        $dueOn = $pick('due_on', $mode === PlanMode::Envelope ? null : $current?->due_on?->toDateString());

        return new PlanItemInput(
            type: $this->has('type') ? PlanItemType::from($this->string('type')->toString()) : $current->type ?? PlanItemType::Expense,
            mode: $mode,
            name: (string) $pick('name', $current?->name),
            categoryId: (int) $pick('category_id', $current?->category_id),
            plannedAmount: (int) $pick('planned_amount', $current?->planned_amount),
            accountId: ($account = $pick('account_id', $current?->account_id)) !== null ? (int) $account : null,
            dueOn: $dueOn !== null ? (string) $dueOn : null,
            sortOrder: (int) $pick('sort_order', $current->sort_order ?? 0),
        );
    }
}
