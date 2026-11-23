<?php

namespace App\Actions\Transfers;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Dua baris transfer dengan transfer_group_id yang sama: keluar (negatif) & masuk (positif).
 */
final class TransferPair
{
    private function __construct(
        public readonly Transaction $out,
        public readonly Transaction $in,
    ) {}

    /** Transfer milik user lain diperlakukan sebagai tidak ada (404). */
    public static function find(User $user, string $groupId, bool $lock = false): self
    {
        $legs = Transaction::ownedBy($user)
            ->where('transfer_group_id', $groupId)
            ->where('type', TransactionType::Transfer->value)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->orderBy('id')
            ->get();

        $out = $legs->first(fn (Transaction $t) => $t->amount < 0);
        $in = $legs->first(fn (Transaction $t) => $t->amount > 0);

        if ($legs->count() !== 2 || $out === null || $in === null) {
            throw new NotFoundHttpException('Transfer tidak ditemukan.');
        }

        return new self($out, $in);
    }

    public function groupId(): string
    {
        return (string) $this->out->transfer_group_id;
    }
}
