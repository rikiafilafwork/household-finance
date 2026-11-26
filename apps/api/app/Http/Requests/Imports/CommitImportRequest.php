<?php

namespace App\Http\Requests\Imports;

use App\Actions\Imports\StageImportRows;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `rows`: baris yang dicentang di preview beserta kategorinya, mis.
 * `[{"id": 12, "category_id": 5}, {"id": 13, "category_id": null}]`. Baris yang tidak dikirim
 * jadi `skipped`. Boleh dikirim ulang apa adanya kalau commit sebelumnya gagal di tengah.
 */
class CommitImportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rows' => ['present', 'array', 'max:'.StageImportRows::MAX_ROWS],
            'rows.*.id' => ['required', 'integer', 'distinct'],
            'rows.*.category_id' => ['present', 'nullable', 'integer'],
        ];
    }

    /** @return array<int, int|null> import_row_id → category_id */
    public function selection(): array
    {
        $selection = [];
        foreach ((array) $this->input('rows', []) as $row) {
            /** @var array{id: int|string, category_id: int|string|null} $row */
            $selection[(int) $row['id']] = $row['category_id'] === null ? null : (int) $row['category_id'];
        }

        return $selection;
    }
}
