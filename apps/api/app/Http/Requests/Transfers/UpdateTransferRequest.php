<?php

namespace App\Http\Requests\Transfers;

use App\Actions\Transfers\TransferInput;
use App\Actions\Transfers\TransferPair;
use App\Http\Requests\Wallets\StoreWalletRequest;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTransferRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_wallet_id' => ['sometimes', 'integer'],
            'to_wallet_id' => ['sometimes', 'integer'],
            'amount' => ['sometimes', 'integer:strict', 'min:1', 'max:'.StoreWalletRequest::MAX_AMOUNT],
            'occurred_on' => ['sometimes', 'date_format:Y-m-d'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'client_id' => ['prohibited'],
        ];
    }

    public function toInput(TransferPair $pair): TransferInput
    {
        return new TransferInput(
            fromWalletId: $this->has('from_wallet_id') ? $this->integer('from_wallet_id') : $pair->out->wallet_id,
            toWalletId: $this->has('to_wallet_id') ? $this->integer('to_wallet_id') : $pair->in->wallet_id,
            amount: $this->has('amount') ? $this->integer('amount') : $pair->in->amount,
            occurredOn: $this->has('occurred_on') ? $this->string('occurred_on')->toString() : $pair->out->occurred_on->toDateString(),
            note: $this->has('note') ? ($this->filled('note') ? $this->string('note')->toString() : null) : $pair->out->note,
        );
    }
}
