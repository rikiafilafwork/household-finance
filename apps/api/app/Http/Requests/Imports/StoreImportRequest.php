<?php

namespace App\Http\Requests\Imports;

use App\Enums\StatementFormat;
use App\Imports\ColumnMapping;
use App\Imports\Exceptions\InvalidColumnMapping;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Upload mutasi (multipart). `column_mapping` dikirim sebagai string JSON supaya indeks kolom tetap
 * integer (field multipart selalu string). Untuk format `generic` boleh dikosongkan kalau akun ini
 * sudah pernah diimpor: pemetaan terakhir dipakai ulang (preset).
 */
class StoreImportRequest extends FormRequest
{
    /** Ukuran file maksimum (KB). Mutasi sebulan biasanya < 200 KB. */
    public const MAX_KB = 5_120;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['required', 'integer'],
            'format' => ['required', Rule::enum(StatementFormat::class)],
            'file' => ['required', 'file', 'max:'.self::MAX_KB, 'extensions:csv,txt'],
            'column_mapping' => ['nullable', 'json', 'max:5000'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('column_mapping') || ! $this->filled('column_mapping')) {
                    return;
                }
                try {
                    $this->columnMapping();
                } catch (InvalidColumnMapping $e) {
                    $validator->errors()->add('column_mapping', $e->getMessage());
                }
            },
        ];
    }

    public function statementFormat(): StatementFormat
    {
        return StatementFormat::from($this->string('format')->toString());
    }

    /** @throws InvalidColumnMapping */
    public function columnMapping(): ?ColumnMapping
    {
        if (! $this->filled('column_mapping')) {
            return null;
        }

        $data = json_decode($this->string('column_mapping')->toString(), true);
        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidColumnMapping('column_mapping harus objek JSON.');
        }

        /** @var array<string, mixed> $data */
        return ColumnMapping::fromArray($data);
    }
}
