<?php

namespace App\Http\Requests\Accounts;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = StoreAccountRequest::MAX_AMOUNT;
        /** @var Account $account */
        $account = $this->route('account');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('accounts')->where('user_id', $account->user_id)->ignore($account->getKey()),
            ],
            'type' => ['sometimes', Rule::enum(AccountType::class)],
            'opening_balance' => ['sometimes', 'integer:strict', "between:-{$max},{$max}"],
            'is_savings' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer:strict', 'between:0,32767'],
            'balance' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.unique' => 'Akun dengan nama ini sudah ada.'];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
