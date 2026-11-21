<?php

namespace App\Http\Requests\Categories;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `kind` tidak bisa diubah: transaksi lama sudah dicocokkan dengan jenisnya.
 */
class UpdateCategoryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Category $category */
        $category = $this->route('category');

        return [
            'kind' => ['prohibited'],
            'name' => [
                'sometimes', 'required', 'string', 'max:60',
                Rule::unique('categories')
                    ->where('household_id', $category->household_id)
                    ->where('kind', $category->kind->value)
                    ->ignore($category->getKey()),
            ],
            'color' => ['sometimes', 'nullable', 'string', 'regex:'.StoreCategoryRequest::COLOR_REGEX],
            'icon' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:'.StoreCategoryRequest::ICON_REGEX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Kategori dengan nama ini sudah ada.',
            'kind.prohibited' => 'Jenis kategori tidak bisa diubah. Buat kategori baru saja.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
