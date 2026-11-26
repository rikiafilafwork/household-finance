<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Enums\StatementFormat;
use App\Imports\ColumnMapping;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Satu file mutasi rekening yang diunggah (Keputusan #13).
 *
 * @property int $account_id
 * @property StatementFormat $bank_format
 * @property array<string, mixed>|null $column_mapping
 * @property list<string>|null $headers
 * @property string $original_filename
 * @property string|null $file_path
 * @property ImportStatus $status
 * @property int $total_rows
 * @property int $new_rows
 * @property int $duplicate_rows
 * @property int $possible_duplicate_rows
 * @property int $invalid_rows
 * @property int $committed_rows
 * @property int $skipped_rows
 * @property string|null $error
 * @property Carbon $created_at
 */
#[Fillable([
    'user_id', 'account_id', 'bank_format', 'column_mapping', 'headers', 'original_filename', 'file_path', 'status',
    'total_rows', 'new_rows', 'duplicate_rows', 'possible_duplicate_rows', 'invalid_rows', 'committed_rows', 'skipped_rows', 'error',
])]
class Import extends Model
{
    use BelongsToUser;

    /** Disk privat (storage/app/private), tidak pernah disajikan publik. */
    public const DISK = 'local';

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bank_format' => StatementFormat::class,
            'column_mapping' => 'array',
            'headers' => 'array',
            'status' => ImportStatus::class,
            'total_rows' => 'integer',
            'new_rows' => 'integer',
            'duplicate_rows' => 'integer',
            'possible_duplicate_rows' => 'integer',
            'invalid_rows' => 'integer',
            'committed_rows' => 'integer',
            'skipped_rows' => 'integer',
        ];
    }

    public function mapping(): ?ColumnMapping
    {
        return $this->column_mapping === null ? null : ColumnMapping::fromArray($this->column_mapping);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<ImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }
}
