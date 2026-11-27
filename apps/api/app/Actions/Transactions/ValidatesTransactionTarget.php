<?php

namespace App\Actions\Transactions;

use App\Enums\CategoryKind;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Aturan bisnis yang butuh DB: akun & kategori milik user ini, tidak diarsip,
 * dan jenis kategori cocok dengan tipe transaksi.
 */
trait ValidatesTransactionTarget
{
    /**
     * Baris lama ber-client_id sama milik user ini (replay idempoten, Keputusan #15).
     * client_id unik per user, jadi client_id milik user lain tidak pernah bertabrakan.
     */
    protected function existingByClientId(User $user, ?string $clientId): ?Transaction
    {
        if ($clientId === null) {
            return null;
        }

        return Transaction::ownedBy($user)->where('client_id', $clientId)->first();
    }

    protected function assertAccountWritable(Account $account, string $field = 'account_id'): void
    {
        if ($account->isArchived()) {
            throw ValidationException::withMessages([$field => 'Rekening ini sudah diarsip.']);
        }
    }

    /**
     * @param  int|null  $keepArchivedId  kategori lama transaksi yang sedang diedit: boleh tetap
     *                                    dipakai walau sudah diarsip (yang baru tidak boleh).
     */
    protected function assertCategoryMatches(User $user, ?int $categoryId, TransactionType $type, ?int $keepArchivedId = null): void
    {
        if ($categoryId === null) {
            return;
        }

        $category = Category::ownedBy($user)->whereKey($categoryId)->first();

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
