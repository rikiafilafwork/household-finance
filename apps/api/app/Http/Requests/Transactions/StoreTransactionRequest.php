<?php

namespace App\Http\Requests\Transactions;

use App\Actions\Transactions\TransactionInput;
use App\Enums\TransactionType;
use App\Http\Requests\Wallets\StoreWalletRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `amount` = magnitudo positif (integer rupiah). Tanda diberikan server sesuai `type`.
 */
class StoreTransactionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'wallet_id' => ['required', 'integer'],
            'type' => ['required', Rule::in([TransactionType::Income->value, TransactionType::Expense->value])],
            'amount' => ['required', 'integer:strict', 'min:1', 'max:'.StoreWalletRequest::MAX_AMOUNT],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'category_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
            // Wajib (Keputusan #11): web membuatnya per submit, mobile per item antrean.
            'client_id' => ['required', 'uuid'],
        ];
    }

    public function toInput(): TransactionInput
    {
        return new TransactionInput(
            walletId: $this->integer('wallet_id'),
            type: TransactionType::from($this->string('type')->toString()),
            amount: $this->integer('amount'),
            occurredOn: $this->string('occurred_on')->toString(),
            categoryId: $this->filled('category_id') ? $this->integer('category_id') : null,
            note: $this->filled('note') ? $this->string('note')->toString() : null,
            clientId: $this->filled('client_id') ? mb_strtolower($this->string('client_id')->toString()) : null,
        );
    }
}
