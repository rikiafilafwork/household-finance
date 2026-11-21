<?php

namespace App\Http\Requests\Wallets;

use Illuminate\Foundation\Http\FormRequest;

class AdjustBalanceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = StoreWalletRequest::MAX_AMOUNT;

        return [
            // Saldo riil boleh negatif (mis. kartu kredit / overdraft).
            'actual_balance' => ['required', 'integer:strict', "between:-{$max},{$max}"],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
