<?php

namespace App\Http\Requests\Auth;

class TokenRequest extends LoginRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }
}
