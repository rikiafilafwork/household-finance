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

describe('accepting', function () {
    /**
     * @return array{0: Household, 1: HouseholdInvitation}
     */
    function pendingInvitation(string $token, string $email = 'pasangan@example.com', HouseholdRole $role = HouseholdRole::Member): array
    {
        [$owner, $household] = ownedHousehold();
        $invitation = HouseholdInvitation::factory()->for($household)->withToken($token)
            ->create(['email' => $email, 'role' => $role, 'invited_by' => $owner->id]);

        return [$household, $invitation];
    }

    it('joins the household with the invited role', function (HouseholdRole $role) {
        [$household, $invitation] = pendingInvitation('token-rahasia', role: $role);
        $user = User::factory()->create(['email' => 'pasangan@example.com']);

        actingAs($user)->postJson('/api/invitations/token-rahasia/accept')
            ->assertOk()
            ->assertJsonPath('data.id', $household->id)
            ->assertJsonPath('data.role', $role->value);

        expect($household->roleOf($user))->toBe($role)
            ->and($invitation->fresh()?->accepted_at)->not->toBeNull();
    })->with([HouseholdRole::Member, HouseholdRole::Owner]);

    it('cannot use the same token twice', function () {
        [$household] = pendingInvitation('sekali-pakai');
        $user = User::factory()->create(['email' => 'pasangan@example.com']);

        actingAs($user)->postJson('/api/invitations/sekali-pakai/accept')->assertOk();
        actingAs($user)->postJson('/api/invitations/sekali-pakai/accept')
            ->assertStatus(410)
            ->assertJsonPath('message', 'Undangan ini sudah dipakai.');

        // Orang lain yang mendapat link bekas juga tidak bisa masuk.
        actingAs(User::factory()->create(['email' => 'penemu-link@example.com']))
            ->postJson('/api/invitations/sekali-pakai/accept')->assertStatus(410);

        expect($household->members()->count())->toBe(2);
    });

    it('requires the invited email (case-insensitive)', function () {
        [$household] = pendingInvitation('untuk-pasangan');

        actingAs(User::factory()->create(['email' => 'orang-lain@example.com']))
            ->postJson('/api/invitations/untuk-pasangan/accept')
            ->assertForbidden();

        $invited = User::factory()->create(['email' => 'Pasangan@Example.com']);
        actingAs($invited)->postJson('/api/invitations/untuk-pasangan/accept')->assertOk();

        expect($household->hasMember($invited))->toBeTrue();
    });

    it('rejects expired and revoked invitations', function (string $state, string $message) {
        [, $household] = ownedHousehold();
        HouseholdInvitation::factory()->for($household)->withToken('tok')->{$state}()->create(['email' => 'pasangan@example.com']);
        $user = User::factory()->create(['email' => 'pasangan@example.com']);

        actingAs($user)->postJson('/api/invitations/tok/accept')
            ->assertStatus(410)
            ->assertJsonPath('message', $message);

        expect($household->hasMember($user))->toBeFalse();
    })->with([
        'expired' => ['expired', 'Undangan ini sudah kedaluwarsa. Minta owner mengirim ulang.'],
        'revoked' => ['revoked', 'Undangan ini sudah dicabut.'],
    ]);

    it('returns 404 for an unknown token', function () {
        actingAs(User::factory()->create())->postJson('/api/invitations/tidak-ada/accept')->assertNotFound();
    });

    it('requires authentication to accept', function () {
        pendingInvitation('tok');

        \Pest\Laravel\postJson('/api/invitations/tok/accept')->assertUnauthorized();
    });

    it('does not duplicate membership for an existing member', function () {
        [$household, $invitation] = pendingInvitation('tok');
        $user = User::factory()->create(['email' => 'pasangan@example.com']);
        $household->members()->attach($user, ['role' => HouseholdRole::Member, 'joined_at' => now()]);

        actingAs($user)->postJson('/api/invitations/tok/accept')->assertOk();

        expect($household->members()->count())->toBe(2)
            ->and($invitation->fresh()?->accepted_at)->not->toBeNull();
    });

    it('previews a pending invitation without login', function () {
        pendingInvitation('pratinjau');

        \Pest\Laravel\getJson('/api/invitations/pratinjau')
            ->assertOk()
            ->assertJsonPath('data.household.name', 'Keluarga Riki')
            ->assertJsonPath('data.invited_by.name', 'Riki')
            ->assertJsonPath('data.email', 'pasangan@example.com')
            ->assertJsonMissingPath('data.token_hash');
    });

    it('does not preview used invitations', function () {
        [, $invitation] = pendingInvitation('bekas');
        $invitation->update(['accepted_at' => now()]);

        \Pest\Laravel\getJson('/api/invitations/bekas')->assertStatus(410);
        \Pest\Laravel\getJson('/api/invitations/tidak-ada')->assertNotFound();
    });

    it('completes the full flow: owner invites, invitee registers and accepts', function () {
        [$owner, $household] = ownedHousehold();
        actingAs($owner)->postJson(inviteUrl($household), ['email' => 'pasangan@example.com'])->assertCreated();

        $token = null;
        Notification::assertSentOnDemand(InvitationSent::class, function (InvitationSent $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $browser = spa()->csrf();
        $browser->post('/api/auth/register', [
            'name' => 'Pasangan',
            'email' => 'pasangan@example.com',
            'password' => 'rahasia-panjang',
            'password_confirmation' => 'rahasia-panjang',
        ])->assertCreated();

        $browser->post("/api/invitations/{$token}/accept")->assertOk()->assertJsonPath('data.role', 'member');
        $browser->get('/api/households')->assertOk()->assertJsonCount(2, 'data'); // household sendiri + yang diundang
    });
});
