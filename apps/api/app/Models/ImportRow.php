<?php

namespace App\Models;

use App\Enums\ImportRowStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris staging import. Tidak punya user_id: selalu diakses lewat `Import::rows()`, dan Import
 * ter-scope per user (BelongsToUser).
 *
 * @property int $import_id
 * @property int $row_number
 * @property string|null $occurred_on
 * @property int|null $amount
 * @property string|null $description
 * @property string|null $fingerprint
 * @property ImportRowStatus $status
 * @property int|null $category_id
 * @property int|null $transaction_id
 * @property string|null $error
 * @property array<int|string, string> $raw
 */
#[Fillable([
    'import_id', 'row_number', 'occurred_on', 'amount', 'description', 'fingerprint', 'status',
    'category_id', 'transaction_id', 'error', 'raw',
])]
class ImportRow extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => ImportRowStatus::class,
            'raw' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Import, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
