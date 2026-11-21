<?php

namespace App\Http\Controllers\Api;

use App\Actions\Households\AcceptInvitation;
use App\Http\Controllers\Controller;
use App\Http\Resources\HouseholdResource;
use App\Models\HouseholdInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sisi penerima undangan: token dari link email (bukan id), jadi di luar grup /households/{household}.
 */
class InvitationAcceptController extends Controller
{
    /**
     * Pratinjau untuk halaman "Terima undangan" (boleh sebelum login): household, pengundang,
     * dan email yang harus dipakai. Pemegang token memang orang yang diundang.
     */
    public function show(string $token, AcceptInvitation $acceptInvitation): JsonResponse
    {
        $invitation = HouseholdInvitation::query()
            ->where('token_hash', HouseholdInvitation::hashToken($token))
            ->with(['household', 'inviter'])
            ->firstOrFail();

        $acceptInvitation->ensureUsable($invitation);

        return response()->json(['data' => [
            'household' => ['name' => $invitation->household->name],
            'invited_by' => ['name' => $invitation->inviter->name],
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]]);
    }

    public function accept(Request $request, string $token, AcceptInvitation $acceptInvitation): HouseholdResource
    {
        /** @var User $user */
        $user = $request->user();

        return new HouseholdResource($acceptInvitation->handle($user, $token));
    }
}
