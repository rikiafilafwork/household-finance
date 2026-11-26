<?php

namespace App\Http\Resources;

use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Status import untuk polling dari web (file_path sengaja tidak dikirim).
 *
 * @mixin Import
 */
class ImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'format' => $this->bank_format->value,
            'column_mapping' => $this->column_mapping,
            'original_filename' => $this->original_filename,
            'headers' => $this->headers, // urutan kolom asli; baris `raw` mengikuti urutan ini
            'status' => $this->status->value,
            'counts' => [
                'total' => $this->total_rows,
                'new' => $this->new_rows,
                'duplicate' => $this->duplicate_rows,
                'possible_duplicate' => $this->possible_duplicate_rows,
                'invalid' => $this->invalid_rows,
                'committed' => $this->committed_rows,
                'skipped' => $this->skipped_rows,
            ],
            'error' => $this->error,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
