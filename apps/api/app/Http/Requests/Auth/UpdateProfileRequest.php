<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            // Nama zona IANA, mis. Asia/Jakarta, Asia/Makassar, Asia/Jayapura.
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all'],
            'email' => ['prohibited'],
            'password' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['password.prohibited' => 'Ganti password lewat PUT /api/auth/password.'];
    }
}
