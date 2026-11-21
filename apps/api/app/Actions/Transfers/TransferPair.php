<?php

namespace App\Actions\Transfers;

use App\Enums\TransactionType;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use App\Reports\VisibleWallets;
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

    public static function find(Household $household, string $groupId, bool $lock = false): self
    {
        $legs = Transaction::query()
            ->where('household_id', $household->getKey())
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

    /** Transfer hanya boleh dilihat/diubah kalau KEDUA wallet terlihat user. */
    public function visibleTo(User $user): bool
    {
        return VisibleWallets::canSee($user, $this->out->wallet) && VisibleWallets::canSee($user, $this->in->wallet);
    }

    public function manageableBy(User $user): bool
    {
        return $this->visibleTo($user)
            && ($this->out->created_by === $user->getKey() || $this->out->household->isOwner($user));
    }
}
