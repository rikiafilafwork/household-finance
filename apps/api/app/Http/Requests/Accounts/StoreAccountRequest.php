<?php

namespace App\Http\Requests\Accounts;

use App\Enums\AccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    /** Batas aman uang: jauh di bawah BIGINT dan Number.MAX_SAFE_INTEGER. */
    public const MAX_AMOUNT = 1_000_000_000_000_000;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('accounts')->where('user_id', $this->user()?->getAuthIdentifier()),
            ],
            'type' => ['required', Rule::enum(AccountType::class)],
            // Uang dikirim sebagai integer JSON rupiah (Keputusan #1); string/float ditolak.
            'opening_balance' => ['sometimes', 'integer:strict', 'between:-'.self::MAX_AMOUNT.','.self::MAX_AMOUNT],
            'is_savings' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer:strict', 'between:0,32767'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.unique' => 'Rekening dengan nama ini sudah ada.'];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
