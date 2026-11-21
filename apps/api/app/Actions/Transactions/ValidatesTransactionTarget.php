<?php

namespace App\Actions\Transactions;

use App\Enums\CategoryKind;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Reports\VisibleWallets;
use Illuminate\Validation\ValidationException;

/**
 * Aturan bisnis yang butuh DB: wallet & kategori milik household ini, terlihat user,
 * tidak diarsip, dan jenis kategori cocok dengan tipe transaksi.
 */
trait ValidatesTransactionTarget
{
    /**
     * Baris lama ber-client_id sama (replay idempoten, Keputusan #11). Replay hanya boleh
     * mengembalikan transaksi yang terlihat oleh pengirim: client_id milik transaksi di wallet
     * privat orang lain tidak boleh jadi jalan pintas melewati VisibleWallets.
     */
    protected function existingByClientId(Household $household, User $actor, ?string $clientId): ?Transaction
    {
        if ($clientId === null) {
            return null;
        }

        $existing = Transaction::query()
            ->where('household_id', $household->getKey())
            ->where('client_id', $clientId)
            ->first();

        if ($existing !== null && ! VisibleWallets::canSee($actor, $existing->wallet)) {
            throw self::clientIdTaken();
        }

        return $existing;
    }

    protected static function clientIdTaken(): ValidationException
    {
        return ValidationException::withMessages(['client_id' => 'client_id sudah dipakai. Buat client_id baru.']);
    }

    protected function assertWalletWritable(Wallet $wallet, Household $household, User $actor, string $field = 'wallet_id'): void
    {
        if ($wallet->household_id !== $household->getKey() || ! VisibleWallets::canSee($actor, $wallet)) {
            throw ValidationException::withMessages([$field => 'Wallet tidak ditemukan.']);
        }

        if ($wallet->isArchived()) {
            throw ValidationException::withMessages([$field => 'Wallet ini sudah diarsip.']);
        }
    }

    /**
     * @param  int|null  $keepArchivedId  kategori lama transaksi yang sedang diedit: boleh tetap
     *                                    dipakai walau sudah diarsip (yang baru tidak boleh).
     */
    protected function assertCategoryMatches(?int $categoryId, TransactionType $type, Household $household, ?int $keepArchivedId = null): void
    {
        if ($categoryId === null) {
            return;
        }

        $category = Category::query()->whereKey($categoryId)->where('household_id', $household->getKey())->first();

        if ($category === null) {
            throw ValidationException::withMessages(['category_id' => 'Kategori tidak ditemukan.']);
        }

        if ($category->kind !== CategoryKind::from($type->value)) {
            throw ValidationException::withMessages(['category_id' => 'Jenis kategori tidak cocok dengan tipe transaksi.']);
        }

        if ($category->archived_at !== null && $category->getKey() !== $keepArchivedId) {
            throw ValidationException::withMessages(['category_id' => 'Kategori ini sudah diarsip.']);
        }
    }
}
