<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Amount ber-tanda dari sudut pandang wallet (Keputusan #2), ditegakkan chk_tx_sign.
 * Buat/ubah/hapus hanya lewat App\Actions\Transactions & Transfers supaya saldo ikut terjaga.
 *
 * @property TransactionType $type
 * @property TransactionStatus $status
 * @property int $amount
 * @property Carbon $occurred_on
 */
#[Fillable([
    'household_id', 'wallet_id', 'category_id', 'type', 'status', 'amount', 'occurred_on', 'note',
    'transfer_group_id', 'client_id', 'recurring_rule_id', 'import_hash', 'created_by', 'updated_by',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Samakan dengan default kolom DB supaya model yang baru dibuat langsung tahu statusnya.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'posted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'amount' => 'integer',
            'occurred_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Hanya transaksi posted (dan belum dihapus) yang memengaruhi saldo. */
    public function affectsBalance(): bool
    {
        return $this->status === TransactionStatus::Posted && ! $this->trashed();
    }
}
