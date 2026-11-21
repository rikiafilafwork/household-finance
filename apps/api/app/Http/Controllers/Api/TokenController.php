<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TokenRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Auth mobile: personal access token Sanctum (Bearer). Web memakai AuthController (session).
 */
class TokenController extends Controller
{
    public function store(TokenRequest $request): JsonResponse
    {
        $credentials = $request->credentials();
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $token = $user->createToken($request->string('device_name')->toString());

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => new UserResource($user),
        ], 201);
    }

    public function destroy(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        // Hanya token Bearer yang bisa dicabut; sesi web keluar lewat /api/auth/logout.
        $plain = $request->bearerToken();
        $token = $plain ? PersonalAccessToken::findToken($plain) : null;

        if ($token === null || ! $token->tokenable?->is($user)) {
            abort(400, 'Request ini tidak memakai personal access token.');
        }

        $token->delete();

        return response()->noContent();
    }
}
