<?php

namespace App\Support;

use App\Enums\CategoryKind;
use App\Models\Category;
use App\Models\User;

/**
 * Kategori bawaan untuk user baru (dipakai saat register).
 * Ikon = nama ikon lucide (dirender di web/mobile).
 */
final class DefaultCategories
{
    /**
     * @return list<array{kind: CategoryKind, name: string, color: string, icon: string}>
     */
    public static function all(): array
    {
        // Architecture.md § Minggu 1: sedikit tapi cukup; sisanya ditambah sendiri di Pengaturan.
        $expense = [
            ['Makan', '#F97316', 'utensils'],
            ['Transport', '#3B82F6', 'car'],
            ['Tagihan', '#8B5CF6', 'receipt'],
            ['Hiburan', '#EC4899', 'popcorn'],
            ['Belanja', '#EAB308', 'shopping-bag'],
            ['Kesehatan', '#EF4444', 'heart-pulse'],
            ['Pendidikan', '#14B8A6', 'graduation-cap'],
            ['Lainnya', '#9CA3AF', 'circle-ellipsis'],
        ];

        $income = [
            ['Gaji', '#16A34A', 'wallet'],
            ['Freelance', '#0EA5E9', 'laptop'],
            ['Lainnya', '#9CA3AF', 'circle-ellipsis'],
        ];

        $rows = [];
        foreach ([[CategoryKind::Expense, $expense], [CategoryKind::Income, $income]] as [$kind, $items]) {
            foreach ($items as [$name, $color, $icon]) {
                $rows[] = ['kind' => $kind, 'name' => $name, 'color' => $color, 'icon' => $icon];
            }
        }

        return $rows;
    }

    /**
     * Idempoten: aman dipanggil ulang. insertOrIgnore bersandar pada uq_category_name.
     * Catatan: upsert($rows, $keys, []) TIDAK idempoten; Laravel mengubahnya jadi insert biasa.
     */
    public static function seedFor(User $user): void
    {
        $now = now();

        $rows = array_map(fn (array $c) => [
            'user_id' => $user->getKey(),
            'kind' => $c['kind']->value,
            'name' => $c['name'],
            'color' => $c['color'],
            'icon' => $c['icon'],
            'created_at' => $now,
            'updated_at' => $now,
        ], self::all());

        Category::ownedBy($user)->toBase()->insertOrIgnore($rows);
    }
}
