<?php

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * @param  array<string, mixed>  $data
 */
function storedNotification(User $user, array $data = [], ?string $readAt = null, string $createdAt = '2026-11-21 10:00:00'): DatabaseNotification
{
    return DatabaseNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'budget_threshold_reached',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => ['household_id' => 1, 'threshold' => 80, ...$data],
        'read_at' => $readAt,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

it('requires authentication', function () {
    getJson('/api/notifications')->assertUnauthorized();
});

it('lists only my notifications, newest first, with the unread count', function () {
    $me = User::factory()->create();
    $old = storedNotification($me, createdAt: '2026-11-01 08:00:00', readAt: '2026-11-02 08:00:00');
    $new = storedNotification($me, ['threshold' => 100], createdAt: '2026-11-20 08:00:00');
    storedNotification(User::factory()->create(), ['secret' => 'punya orang lain']);

    actingAs($me)->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $new->id)
        ->assertJsonPath('data.0.type', 'budget_threshold_reached')
        ->assertJsonPath('data.0.payload.threshold', 100)
        ->assertJsonPath('data.0.read_at', null)
        ->assertJsonPath('data.1.id', $old->id)
        ->assertJsonPath('meta.unread_count', 1)
        ->assertDontSee('punya orang lain');
});

it('filters unread and by household', function () {
    $me = User::factory()->create();
    storedNotification($me, ['household_id' => 1], readAt: '2026-11-21 11:00:00');
    $unreadA = storedNotification($me, ['household_id' => 1]);
    $unreadB = storedNotification($me, ['household_id' => 2]);

    actingAs($me)->getJson('/api/notifications?unread=1')->assertJsonCount(2, 'data');
    actingAs($me)->getJson('/api/notifications?unread=1&household_id=2')
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $unreadB->id);
    actingAs($me)->getJson('/api/notifications?household_id=1')->assertJsonCount(2, 'data');
    expect($unreadA->fresh()?->read_at)->toBeNull();
});

it('marks one as read, idempotently', function () {
    $me = User::factory()->create();
    $n = storedNotification($me);

    $readAt = actingAs($me)->postJson("/api/notifications/{$n->id}/read")->assertOk()->json('data.read_at');
    expect($readAt)->not->toBeNull();
    actingAs($me)->postJson("/api/notifications/{$n->id}/read")->assertOk()->assertJsonPath('data.read_at', $readAt);
    actingAs($me)->getJson('/api/notifications')->assertJsonPath('meta.unread_count', 0);
});

it('returns 404 for someone else\'s notification and leaves it unread', function () {
    $other = storedNotification(User::factory()->create());

    actingAs(User::factory()->create())->postJson("/api/notifications/{$other->id}/read")->assertNotFound();
    expect($other->fresh()?->read_at)->toBeNull();
});

it('marks all of mine as read without touching others', function () {
    $me = User::factory()->create();
    storedNotification($me);
    storedNotification($me);
    $other = storedNotification(User::factory()->create());

    actingAs($me)->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('data.marked', 2);
    expect($other->fresh()?->read_at)->toBeNull();
});
