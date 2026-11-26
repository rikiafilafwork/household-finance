<?php

namespace App\Http\Requests\Savings;

use App\Http\Requests\Accounts\StoreAccountRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `amount` bertanda: positif = tambah alokasi, negatif = kurangi.
 */
class AllocateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = StoreAccountRequest::MAX_AMOUNT;

        return [
            'amount' => ['required', 'integer:strict', 'not_in:0', "between:-{$max},{$max}"],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:255'],
            'client_id' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['amount.not_in' => 'Nominal tidak boleh 0.'];
    }

    public function clientId(): string
    {
        return mb_strtolower($this->string('client_id')->toString());
    }

    public function note(): ?string
    {
        return $this->filled('note') ? $this->string('note')->toString() : null;
    }
}
