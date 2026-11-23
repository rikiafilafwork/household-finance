<?php

namespace App\Http\Requests\Plan;

use App\Actions\Plan\CheckPlanItemInput;
use App\Http\Requests\Accounts\StoreAccountRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Centang item: semua opsional kecuali client_id. `amount` = magnitudo (nominal aktual), boleh
 * berbeda dari rencana.
 */
class CheckPlanItemRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Wajib (Keputusan #15): web per submit form, mobile per item antrean.
            'client_id' => ['required', 'uuid'],
            'account_id' => ['sometimes', 'nullable', 'integer'],
            'amount' => ['sometimes', 'nullable', 'integer:strict', 'min:1', 'max:'.StoreAccountRequest::MAX_AMOUNT],
            'occurred_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function toInput(): CheckPlanItemInput
    {
        return new CheckPlanItemInput(
            clientId: mb_strtolower($this->string('client_id')->toString()),
            accountId: $this->filled('account_id') ? $this->integer('account_id') : null,
            amount: $this->filled('amount') ? $this->integer('amount') : null,
            occurredOn: $this->filled('occurred_on') ? $this->string('occurred_on')->toString() : null,
            note: $this->filled('note') ? $this->string('note')->toString() : null,
        );
    }
}
