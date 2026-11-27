<?php

namespace App\Http\Requests\Savings;

use App\Http\Requests\Accounts\StoreAccountRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveAllocationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Tujuan milik orang lain: pesan sama dengan tujuan yang tidak ada.
            'to_goal_id' => [
                'required', 'integer:strict',
                Rule::exists('savings_goals', 'id')->where('user_id', $this->user()?->getAuthIdentifier()),
            ],
            'amount' => ['required', 'integer:strict', 'between:1,'.StoreAccountRequest::MAX_AMOUNT],
            'note' => ['nullable', 'string', 'max:255'],
            'client_id' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['to_goal_id.exists' => 'Tujuan tabungan tidak ditemukan.'];
    }

    public function clientId(): string
    {
        return mb_strtolower($this->string('client_id')->toString());
    }

    public function note(): ?string
    {
        return $this->filled('note') ? $this->string('note')->toString() : null;
    }
}
