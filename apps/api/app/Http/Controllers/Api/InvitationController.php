<?php

namespace App\Http\Controllers\Api;

use App\Actions\Households\InviteMember;
use App\Enums\HouseholdRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Households\StoreInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class InvitationController extends Controller
{
    /** Undangan yang masih menunggu (owner saja). */
    public function index(Household $household): AnonymousResourceCollection
    {
        Gate::authorize('manageMembers', $household);

        return InvitationResource::collection(
            $household->invitations()->pending()->with('inviter')->latest('id')->get()
        );
    }

    public function store(StoreInvitationRequest $request, Household $household, InviteMember $inviteMember): JsonResponse
    {
        Gate::authorize('manageMembers', $household);

        /** @var User $user */
        $user = $request->user();

        $invitation = $inviteMember->handle(
            $household,
            $user,
            $request->string('email')->toString(),
            $request->enum('role', HouseholdRole::class) ?? HouseholdRole::Member,
        );

        return (new InvitationResource($invitation->load('inviter')))->response()->setStatusCode(201);
    }

    /** Cabut undangan (idempoten). */
    public function destroy(Household $household, HouseholdInvitation $invitation): Response
    {
        Gate::authorize('manageMembers', $household);

        if ($invitation->isPending()) {
            $invitation->update(['revoked_at' => now()]);
        }

        return response()->noContent();
    }
}
