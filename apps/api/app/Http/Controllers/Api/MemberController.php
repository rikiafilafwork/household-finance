<?php

namespace App\Http\Controllers\Api;

use App\Actions\Households\ChangeMemberRole;
use App\Actions\Households\RemoveMember;
use App\Enums\HouseholdRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\MemberResource;
use App\Models\Household;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * {member} di-resolve lewat $household->members() (scopeBindings), jadi user yang bukan
 * anggota household ini otomatis 404.
 */
class MemberController extends Controller
{
    public function index(Household $household): AnonymousResourceCollection
    {
        Gate::authorize('view', $household);

        return MemberResource::collection(
            $household->members()->orderBy('household_members.joined_at')->get()
        );
    }

    public function update(Request $request, Household $household, User $member, ChangeMemberRole $changeMemberRole): MemberResource
    {
        Gate::authorize('manageMembers', $household);

        $validated = $request->validate(['role' => ['required', Rule::enum(HouseholdRole::class)]]);

        $changeMemberRole->handle($household, $member, HouseholdRole::from($validated['role']));

        return new MemberResource($household->members()->whereKey($member->getKey())->firstOrFail());
    }

    /** Owner mengeluarkan anggota, atau anggota keluar sendiri. */
    public function destroy(Request $request, Household $household, User $member, RemoveMember $removeMember): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->is($member)) {
            Gate::authorize('manageMembers', $household);
        }

        $removeMember->handle($household, $member);

        return response()->noContent();
    }
}
