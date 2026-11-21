<?php

namespace App\Http\Requests\Budgets;

use App\Http\Requests\Wallets\StoreWalletRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /budgets/{month}: daftar lengkap budget bulan itu. Kategori yang tidak ada di daftar
 * dihapus budget-nya; `items: []` mengosongkan bulan itu.
 */
class SetBudgetsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'max:200'],
            'items.*' => ['array:category_id,limit_amount'],
            'items.*.category_id' => ['required', 'integer', 'distinct'],
            // Uang = integer JSON rupiah (Keputusan #1). 0 tidak boleh: hapus dari daftar saja.
            'items.*.limit_amount' => ['required', 'integer:strict', 'min:1', 'max:'.StoreWalletRequest::MAX_AMOUNT],
        ];
    }

    /**
     * @return array<int, int> [category_id => limit_amount]
     */
    public function limits(): array
    {
        /** @var list<array{category_id: int|string, limit_amount: int}> $items */
        $items = $this->validated('items');
        $limits = [];
        foreach ($items as $item) {
            $limits[(int) $item['category_id']] = $item['limit_amount'];
        }

        return $limits;
    }
}
