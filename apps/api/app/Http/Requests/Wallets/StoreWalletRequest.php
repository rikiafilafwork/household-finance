<?php

namespace App\Http\Requests\Wallets;

use App\Enums\WalletType;
use App\Enums\WalletVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWalletRequest extends FormRequest
{
    /** Batas aman uang: jauh di bawah BIGINT dan Number.MAX_SAFE_INTEGER. */
    public const MAX_AMOUNT = 1_000_000_000_000_000;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(WalletType::class)],
            'visibility' => ['sometimes', Rule::enum(WalletVisibility::class)],
            // Uang dikirim sebagai integer JSON rupiah (Keputusan #1); string/float ditolak.
            'opening_balance' => ['sometimes', 'integer:strict', 'between:-'.self::MAX_AMOUNT.','.self::MAX_AMOUNT],
        ];
    }

    public function visibility(): WalletVisibility
    {
        return $this->enum('visibility', WalletVisibility::class) ?? WalletVisibility::Shared;
    }
}
