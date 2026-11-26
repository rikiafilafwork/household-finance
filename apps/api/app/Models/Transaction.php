<?php

namespace App\Models;

use App\Enums\TransactionType;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Amount ber-tanda dari sudut pandang akun (Keputusan #2), ditegakkan chk_tx_sign.
 * Buat/ubah/hapus hanya lewat App\Actions\Transactions & Transfers supaya saldo ikut terjaga.
 *
 * @property TransactionType $type
 * @property int $amount
 * @property Carbon $occurred_on
 */
#[Fillable([
    'user_id', 'account_id', 'category_id', 'type', 'amount', 'occurred_on', 'note',
    'transfer_group_id', 'client_id', 'plan_item_id', 'import_hash',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToUser, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => 'integer',
            'occurred_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Log alokasi "Gunakan tabungan" yang menunjuk transaksi ini (paling banyak satu, uq_alloc_transaction).
     *
     * @return HasOne<SavingsAllocation, $this>
     */
    public function savingsAllocation(): HasOne
    {
        return $this->hasOne(SavingsAllocation::class);
    }
}
