<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Log alokasi (append-only): amount bertanda, ≠ 0. `transaction_id` terisi untuk "Gunakan tabungan".
 *
 * @property int $savings_goal_id
 * @property int $amount
 * @property Carbon $occurred_on
 * @property int|null $transaction_id
 * @property string|null $move_group_id
 * @property string|null $client_id
 */
#[Fillable([
    'user_id', 'savings_goal_id', 'amount', 'occurred_on', 'note', 'transaction_id', 'move_group_id', 'client_id',
])]
class SavingsAllocation extends Model
{
    use BelongsToUser;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'occurred_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<SavingsGoal, $this>
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(SavingsGoal::class, 'savings_goal_id');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
