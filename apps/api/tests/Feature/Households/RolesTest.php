<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Household dengan satu owner dan satu member.
 *
 * @return array{0: Household, 1: User, 2: User}
 */
function householdWithMember(): array
{
    $owner = User::factory()->create(['name' => 'Owner']);
    $member = User::factory()->create(['name' => 'Member']);
    $household = Household::factory()->withOwner($owner)->create();
    $household->members()->attach($member, ['role' => HouseholdRole::Member, 'joined_at' => now()->addSecond()]);

    return [$household, $owner, $member];
}

function memberUrl(Household $household, ?User $member = null): string
{
    return "/api/households/{$household->id}/members".($member ? "/{$member->id}" : '');
}

describe('listing', function () {
    it('shows members with roles to every member', function () {
        [$household, $owner, $member] = householdWithMember();

        actingAs($member)->getJson(memberUrl($household))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $owner->id)
            ->assertJsonPath('data.0.role', 'owner')
            ->assertJsonPath('data.1.role', 'member');
    });

    it('hides members from outsiders', function () {
        [$household] = householdWithMember();

        actingAs(User::factory()->create())->getJson(memberUrl($household))->assertForbidden();
    });
});

describe('changing roles', function () {
    it('lets the owner promote and demote a member', function () {
        [$household, $owner, $member] = householdWithMember();

        actingAs($owner)->patchJson(memberUrl($household, $member), ['role' => 'owner'])
            ->assertOk()
            ->assertJsonPath('data.role', 'owner');
        expect($household->roleOf($member))->toBe(HouseholdRole::Owner);

        actingAs($owner)->patchJson(memberUrl($household, $member), ['role' => 'member'])->assertOk();
        expect($household->roleOf($member))->toBe(HouseholdRole::Member);
    });

    it('forbids members from changing roles', function () {
        [$household, $owner, $member] = householdWithMember();

        actingAs($member)->patchJson(memberUrl($household, $member), ['role' => 'owner'])->assertForbidden();
        actingAs($member)->patchJson(memberUrl($household, $owner), ['role' => 'member'])->assertForbidden();

        expect($household->roleOf($member))->toBe(HouseholdRole::Member);
    });

    it('does not let the last owner step down', function () {
        [$household, $owner] = householdWithMember();

        actingAs($owner)->patchJson(memberUrl($household, $owner), ['role' => 'member'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        expect($household->roleOf($owner))->toBe(HouseholdRole::Owner);
    });

    it('lets an owner step down when another owner exists', function () {
        [$household, $owner, $member] = householdWithMember();
        $household->members()->updateExistingPivot($member->id, ['role' => HouseholdRole::Owner]);

        actingAs($owner)->patchJson(memberUrl($household, $owner), ['role' => 'member'])->assertOk();

        expect($household->roleOf($owner))->toBe(HouseholdRole::Member)
            ->and($household->roleOf($member))->toBe(HouseholdRole::Owner);
    });

    it('validates the role', function () {
        [$household, $owner, $member] = householdWithMember();

        actingAs($owner)->patchJson(memberUrl($household, $member), ['role' => 'admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    });
});

describe('removing', function () {
    it('lets the owner remove a member, who then loses access', function () {
        [$household, $owner, $member] = householdWithMember();

        actingAs($owner)->deleteJson(memberUrl($household, $member))->assertNoContent();

        expect($household->hasMember($member))->toBeFalse();
        app('auth')->forgetGuards();
        actingAs($member)->getJson("/api/households/{$household->id}")->assertForbidden();
    });

    it('forbids members from removing others', function () {
        [$household, $owner, $member] = householdWithMember();

        actingAs($member)->deleteJson(memberUrl($household, $owner))->assertForbidden();

        expect($household->hasMember($owner))->toBeTrue();
    });

    it('lets a member leave by themselves', function () {
        [$household, , $member] = householdWithMember();

        actingAs($member)->deleteJson(memberUrl($household, $member))->assertNoContent();

        expect($household->hasMember($member))->toBeFalse();
    });

    it('does not let the last owner be removed or leave', function () {
        [$household, $owner] = householdWithMember();

        actingAs($owner)->deleteJson(memberUrl($household, $owner))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        expect($household->hasMember($owner))->toBeTrue();
    });

    it('returns 404 for users outside the household', function () {
        [$household, $owner] = householdWithMember();
        $outsider = User::factory()->create();

        actingAs($owner)->deleteJson(memberUrl($household, $outsider))->assertNotFound();
        actingAs($owner)->patchJson(memberUrl($household, $outsider), ['role' => 'owner'])->assertNotFound();
    });

    it('does not leak membership across households', function () {
        [$household, $owner] = householdWithMember();
        [, , $otherMember] = householdWithMember();

        // otherMember anggota household lain: tidak bisa dihapus lewat household ini.
        actingAs($owner)->deleteJson(memberUrl($household, $otherMember))->assertNotFound();
    });
});
