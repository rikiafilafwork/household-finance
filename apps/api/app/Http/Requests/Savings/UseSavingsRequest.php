<?php

namespace App\Http\Requests\Savings;

use App\Http\Requests\Accounts\StoreAccountRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Gunakan tabungan": `amount` = magnitudo positif. Kategori pengeluaran milik user dicek di
 * CreateTransaction (milik orang lain = pesan sama dengan yang tidak ada).
 */
class UseSavingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer:strict', 'between:1,'.StoreAccountRequest::MAX_AMOUNT],
            'category_id' => ['required', 'integer:strict'],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:255'],
            'client_id' => ['required', 'uuid'],
        ];
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
