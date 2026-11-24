<?php

namespace App\Plan;

use App\Enums\CategoryKind;
use App\Enums\PlanItemType;
use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Kategori & akun default item rencana / template: milik user, jenis kategori cocok, tidak diarsip
 * (kecuali yang sudah dipakai sebelumnya — data lama tetap bisa disimpan ulang).
 */
final class PlanReferences
{
    public static function assert(
        User $user,
        PlanItemType $type,
        int $categoryId,
        ?int $accountId,
        ?int $keepArchivedCategoryId = null,
        ?int $keepArchivedAccountId = null,
    ): void {
        $category = Category::ownedBy($user)->whereKey($categoryId)->first();
        if ($category === null) {
            throw ValidationException::withMessages(['category_id' => 'Kategori tidak ditemukan.']);
        }
        if ($category->kind !== CategoryKind::from($type->value)) {
            throw ValidationException::withMessages(['category_id' => 'Jenis kategori tidak cocok dengan jenis item.']);
        }
        if ($category->archived_at !== null && $category->getKey() !== $keepArchivedCategoryId) {
            throw ValidationException::withMessages(['category_id' => 'Kategori ini sudah diarsip.']);
        }

        if ($accountId !== null) {
            $account = Account::ownedBy($user)->whereKey($accountId)->first();
            if ($account === null) {
                throw ValidationException::withMessages(['account_id' => 'Akun tidak ditemukan.']);
            }
            if ($account->isArchived() && $account->getKey() !== $keepArchivedAccountId) {
                throw ValidationException::withMessages(['account_id' => 'Akun ini sudah diarsip.']);
            }
        }
    }
}
