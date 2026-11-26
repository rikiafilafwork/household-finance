<?php

namespace App\Http\Requests\Savings;

use App\Http\Requests\Accounts\StoreAccountRequest;
use App\Models\SavingsGoal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSavingsGoalRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var SavingsGoal $goal */
        $goal = $this->route('savingsGoal');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('savings_goals')->where('user_id', $goal->user_id)->ignore($goal->getKey()),
            ],
            'target_amount' => ['sometimes', 'integer:strict', 'between:1,'.StoreAccountRequest::MAX_AMOUNT],
            'target_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sort_order' => ['sometimes', 'integer:strict', 'between:0,32767'],
            // Rekening tujuan tidak bisa dipindah (alokasi terikat ke saldo rekening itu).
            'account_id' => ['prohibited'],
            'allocated_amount' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.unique' => 'Tujuan dengan nama ini sudah ada.'];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
