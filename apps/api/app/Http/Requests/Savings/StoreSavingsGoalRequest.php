<?php

namespace App\Http\Requests\Savings;

use App\Http\Requests\Accounts\StoreAccountRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSavingsGoalRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()?->getAuthIdentifier();

        return [
            // Rekening orang lain, bukan tabungan, atau diarsip: pesan sama dengan rekening yang tidak ada.
            'account_id' => [
                'required', 'integer:strict',
                Rule::exists('accounts', 'id')->where('user_id', $userId)->where('is_savings', true)->whereNull('archived_at'),
            ],
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('savings_goals')->where('user_id', $userId),
            ],
            'target_amount' => ['required', 'integer:strict', 'between:1,'.StoreAccountRequest::MAX_AMOUNT],
            'target_date' => ['nullable', 'date_format:Y-m-d'],
            'sort_order' => ['sometimes', 'integer:strict', 'between:0,32767'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Tujuan dengan nama ini sudah ada.',
            'account_id.exists' => 'Rekening tabungan tidak ditemukan.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
