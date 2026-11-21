<?php

namespace App\Http\Requests\Households;

use Illuminate\Foundation\Http\FormRequest;

class StoreHouseholdRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
        ];
    }
}
