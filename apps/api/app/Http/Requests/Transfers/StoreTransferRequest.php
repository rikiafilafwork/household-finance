<?php

namespace App\Http\Requests\Transfers;

use App\Actions\Transfers\TransferInput;
use App\Http\Requests\Wallets\StoreWalletRequest;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransferRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_wallet_id' => ['required', 'integer'],
            'to_wallet_id' => ['required', 'integer', 'different:from_wallet_id'],
            'amount' => ['required', 'integer:strict', 'min:1', 'max:'.StoreWalletRequest::MAX_AMOUNT],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:255'],
            // Wajib (Keputusan #11): web membuatnya per submit, mobile per item antrean.
            'client_id' => ['required', 'uuid'],
        ];
    }

    public function toInput(): TransferInput
    {
        return new TransferInput(
            fromWalletId: $this->integer('from_wallet_id'),
            toWalletId: $this->integer('to_wallet_id'),
            amount: $this->integer('amount'),
            occurredOn: $this->string('occurred_on')->toString(),
            note: $this->filled('note') ? $this->string('note')->toString() : null,
            clientId: $this->filled('client_id') ? mb_strtolower($this->string('client_id')->toString()) : null,
        );
    }
}
