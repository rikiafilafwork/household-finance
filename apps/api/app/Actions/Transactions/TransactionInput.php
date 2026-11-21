<?php

namespace App\Actions\Transactions;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;

/**
 * Input transaksi income/expense. `amount` = MAGNITUDO positif dari klien;
 * tanda diberikan server sesuai tipe (Keputusan #2), klien tidak pernah mengirim tanda.
 */
final readonly class TransactionInput
{
    public function __construct(
        public int $walletId,
        public TransactionType $type,
        public int $amount,
        public string $occurredOn,
        public ?int $categoryId = null,
        public ?string $note = null,
        public ?string $clientId = null,
        public TransactionStatus $status = TransactionStatus::Posted,
    ) {}

    public function signedAmount(): int
    {
        return match ($this->type) {
            TransactionType::Income => abs($this->amount),
            TransactionType::Expense => -abs($this->amount),
            default => throw new \LogicException('TransactionInput hanya untuk income/expense.'),
        };
    }
}
