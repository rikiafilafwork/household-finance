<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Pengaturan → Profil: nama, zona waktu (Keputusan #12), ganti password.
 */
class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request): UserResource
    {
        $user = $this->user($request);
        $user->update($request->validated());

        return new UserResource($user);
    }

    /**
     * Ganti password: token mobile lain dicabut (perangkat yang mungkin hilang ikut keluar),
     * token yang sedang dipakai (kalau request ini lewat Bearer) tetap berlaku.
     */
    public function password(UpdatePasswordRequest $request): Response
    {
        $user = $this->user($request);
        $user->update(['password' => $request->string('password')->toString()]);

        $plain = $request->bearerToken();
        $current = $plain ? PersonalAccessToken::findToken($plain) : null;
        $user->tokens()->when($current !== null, fn ($q) => $q->whereKeyNot($current?->getKey()))->delete();

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
