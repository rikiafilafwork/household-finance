<?php

namespace App\Http\Requests\Transactions;

use App\Actions\Transactions\TransactionInput;
use App\Enums\TransactionType;
use App\Http\Requests\Wallets\StoreWalletRequest;
use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH parsial: field yang tidak dikirim memakai nilai transaksi saat ini.
 */
class UpdateTransactionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'wallet_id' => ['sometimes', 'integer'],
            'type' => ['sometimes', Rule::in([TransactionType::Income->value, TransactionType::Expense->value])],
            'amount' => ['sometimes', 'integer:strict', 'min:1', 'max:'.StoreWalletRequest::MAX_AMOUNT],
            'occurred_on' => ['sometimes', 'date_format:Y-m-d'],
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['prohibited'], // konfirmasi pending lewat endpoint khusus (#46)
            'client_id' => ['prohibited'],
        ];
    }

    public function toInput(Transaction $current): TransactionInput
    {
        $type = $this->has('type') ? TransactionType::from($this->string('type')->toString()) : $current->type;

        return new TransactionInput(
            walletId: $this->has('wallet_id') ? $this->integer('wallet_id') : $current->wallet_id,
            type: $type,
            amount: $this->has('amount') ? $this->integer('amount') : abs($current->amount),
            occurredOn: $this->has('occurred_on') ? $this->string('occurred_on')->toString() : $current->occurred_on->toDateString(),
            // Ganti tipe tanpa kategori baru → kategori lama (jenis lain) dilepas.
            categoryId: $this->has('category_id')
                ? ($this->filled('category_id') ? $this->integer('category_id') : null)
                : ($type === $current->type ? $current->category_id : null),
            note: $this->has('note') ? ($this->filled('note') ? $this->string('note')->toString() : null) : $current->note,
            status: $current->status,
        );
    }
}
