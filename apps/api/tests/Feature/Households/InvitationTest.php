<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use App\Notifications\InvitationSent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;

beforeEach(fn () => Notification::fake());

/**
 * Household dengan owner "Riki".
 *
 * @return array{0: User, 1: Household}
 */
function ownedHousehold(): array
{
    $owner = User::factory()->create(['name' => 'Riki']);

    return [$owner, Household::factory()->withOwner($owner)->create(['name' => 'Keluarga Riki'])];
}

function addMember(Household $household, HouseholdRole $role = HouseholdRole::Member): User
{
    $user = User::factory()->create();
    $household->members()->attach($user, ['role' => $role, 'joined_at' => now()]);

    return $user;
}

function inviteUrl(Household $household): string
{
    return "/api/households/{$household->id}/invitations";
}

describe('sending', function () {
    it('lets the owner invite an email and stores only the token hash', function () {
        [$owner, $household] = ownedHousehold();
        actingAs($owner)->postJson(inviteUrl($household), ['email' => ' Pasangan@Example.com '])
            ->assertCreated()
            ->assertJsonPath('data.email', 'pasangan@example.com')
            ->assertJsonPath('data.role', 'member')
            ->assertJsonMissingPath('data.token_hash');

        $invitation = HouseholdInvitation::sole();
        expect(now()->diffInDays($invitation->expires_at))->toBeGreaterThan(6.99)->toBeLessThan(7.01);

        Notification::assertSentOnDemand(
            InvitationSent::class,
            function (InvitationSent $notification, array $channels, AnonymousNotifiable $notifiable) use ($invitation) {
                return $notifiable->routes['mail'] === 'pasangan@example.com'
                    && HouseholdInvitation::hashToken($notification->token) === $invitation->token_hash
                    && $notification->token !== $invitation->token_hash
                    && str_ends_with($notification->acceptUrl(), '/invitations/'.$notification->token);
            }
        );
    });

    it('queues the invitation email', function () {
        expect(new InvitationSent(HouseholdInvitation::factory()->make(), 'x'))->toBeInstanceOf(ShouldQueue::class);
    });

    it('can invite as owner', function () {
        [$owner, $household] = ownedHousehold();
        actingAs($owner)->postJson(inviteUrl($household), ['email' => 'a@example.com', 'role' => 'owner'])
            ->assertCreated()
            ->assertJsonPath('data.role', 'owner');
    });

    it('forbids members and outsiders from inviting', function () {
        [$owner, $household] = ownedHousehold();
        $member = addMember($household);

        actingAs($member)->postJson(inviteUrl($household), ['email' => 'x@example.com'])->assertForbidden();
        actingAs(User::factory()->create())->postJson(inviteUrl($household), ['email' => 'x@example.com'])->assertForbidden();

        Notification::assertNothingSent();
        expect(HouseholdInvitation::count())->toBe(0);
    });

    it('rejects inviting an existing member', function () {
        [$owner, $household] = ownedHousehold();
        actingAs($owner)->postJson(inviteUrl($household), ['email' => strtoupper($owner->email)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    });

    it('validates the payload', function (array $payload, string $field) {
        [$owner, $household] = ownedHousehold();
        actingAs($owner)->postJson(inviteUrl($household), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'missing email' => [[], 'email'],
        'invalid email' => [['email' => 'bukan-email'], 'email'],
        'unknown role' => [['email' => 'a@example.com', 'role' => 'admin'], 'role'],
    ]);

    it('re-inviting the same email revokes the previous pending invitation', function () {
        [$owner, $household] = ownedHousehold();
        actingAs($owner)->postJson(inviteUrl($household), ['email' => 'a@example.com'])->assertCreated();
        actingAs($owner)->postJson(inviteUrl($household), ['email' => 'a@example.com'])->assertCreated();

        expect(HouseholdInvitation::count())->toBe(2)
            ->and(HouseholdInvitation::pending()->count())->toBe(1)
            ->and(HouseholdInvitation::oldest('id')->first()?->revoked_at)->not->toBeNull();
    });
});

describe('managing', function () {
    it('lists only pending invitations to the owner', function () {
        [$owner, $household] = ownedHousehold();
        HouseholdInvitation::factory()->for($household)->create(['email' => 'pending@example.com']);
        HouseholdInvitation::factory()->for($household)->expired()->create();
        HouseholdInvitation::factory()->for($household)->revoked()->create();
        HouseholdInvitation::factory()->for($household)->accepted()->create();
        HouseholdInvitation::factory()->create(); // household lain

        actingAs($owner)->getJson(inviteUrl($household))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'pending@example.com');
    });

    it('lets the owner revoke an invitation', function () {
        [$owner, $household] = ownedHousehold();
        $invitation = HouseholdInvitation::factory()->for($household)->create();

        actingAs($owner)->deleteJson(inviteUrl($household)."/{$invitation->id}")->assertNoContent();

        expect($invitation->fresh()?->revoked_at)->not->toBeNull()
            ->and($invitation->fresh()?->isPending())->toBeFalse();
    });

    it('scopes the invitation to its household', function () {
        [$owner, $household] = ownedHousehold();
        $other = HouseholdInvitation::factory()->create();

        actingAs($owner)->deleteJson(inviteUrl($household)."/{$other->id}")->assertNotFound();
        expect($other->fresh()?->revoked_at)->toBeNull();
    });

    it('forbids members from listing or revoking', function () {
        [$owner, $household] = ownedHousehold();
        $member = addMember($household);
        $invitation = HouseholdInvitation::factory()->for($household)->create();

        actingAs($member)->getJson(inviteUrl($household))->assertForbidden();
        actingAs($member)->deleteJson(inviteUrl($household)."/{$invitation->id}")->assertForbidden();
    });
});
