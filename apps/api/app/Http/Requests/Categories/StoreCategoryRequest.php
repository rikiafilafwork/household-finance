<?php

namespace App\Http\Requests\Categories;

use App\Enums\CategoryKind;
use App\Models\Household;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    /** `#RRGGBB` atau `#RRGGBBAA` (kolom VARCHAR(9)). */
    public const COLOR_REGEX = '/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/';

    /** Nama ikon lucide, mis. `shopping-basket`. */
    public const ICON_REGEX = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Household $household */
        $household = $this->route('household');

        return [
            'kind' => ['required', Rule::enum(CategoryKind::class)],
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('categories')
                    ->where('household_id', $household->getKey())
                    ->where('kind', $this->string('kind')->toString()),
            ],
            'color' => ['nullable', 'string', 'regex:'.self::COLOR_REGEX],
            'icon' => ['nullable', 'string', 'max:40', 'regex:'.self::ICON_REGEX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.unique' => 'Kategori dengan nama ini sudah ada.'];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
