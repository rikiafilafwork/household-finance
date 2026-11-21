<?php

namespace App\Http\Requests\Wallets;

use App\Enums\WalletType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Visibility tidak bisa diubah: memindahkan wallet private ke shared (atau sebaliknya)
 * mengubah siapa yang bisa melihat riwayatnya dan budget mana yang terpengaruh.
 */
class UpdateWalletRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = StoreWalletRequest::MAX_AMOUNT;

        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'type' => ['sometimes', Rule::enum(WalletType::class)],
            'opening_balance' => ['sometimes', 'integer:strict', "between:-{$max},{$max}"],
            'visibility' => ['prohibited'],
            'balance' => ['prohibited'],
        ];
    }
}
