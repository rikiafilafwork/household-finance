<?php

use App\Actions\Accounts\ReconcileAccounts;
use App\Console\Commands\DemoUserCommand;
use App\Models\Account;
use App\Models\PlanItem;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Storage::fake('local');
    CarbonImmutable::setTestNow('2026-11-24 03:00:00 UTC');
});
afterEach(fn () => CarbonImmutable::setTestNow());

it('creates a demo user with consistent balances and a password only in the credentials file', function () {
    artisan('demo:user')->assertSuccessful()->doesntExpectOutputToContain('password:');

    $user = User::where('email', 'demo@example.test')->firstOrFail();
    expect(Account::ownedBy($user)->count())->toBe(4)
        ->and(PlanItem::ownedBy($user)->distinct()->count('month'))->toBe(6)
        ->and(Transaction::ownedBy($user)->count())->toBeGreaterThan(100)
        ->and(Account::ownedBy($user)->where('balance', '<', 0)->exists())->toBeFalse()
        ->and(app(ReconcileAccounts::class)->mismatches($user->getKey()))->toBeEmpty();

    preg_match('/password: (\S+)/', Storage::disk('local')->get(DemoUserCommand::CREDENTIALS_FILE) ?? '', $m);
    postJson('/api/auth/token', ['email' => 'demo@example.test', 'password' => $m[1] ?? '', 'device_name' => 'test'])->assertCreated();
});

it('refuses to overwrite an existing demo user unless --fresh, and recreates it with --fresh', function () {
    artisan('demo:user')->assertSuccessful();
    $first = User::where('email', 'demo@example.test')->value('id');

    artisan('demo:user')->assertFailed();
    artisan('demo:user', ['--fresh' => true])->assertSuccessful();

    expect(User::where('email', 'demo@example.test')->value('id'))->not->toBe($first)
        ->and(Transaction::withoutGlobalScopes()->withTrashed()->where('user_id', $first)->exists())->toBeFalse();
});

it('does not touch other users', function () {
    $other = User::factory()->create();
    Account::factory()->forUser($other)->opening(1_000)->create();

    artisan('demo:user')->assertSuccessful();
    artisan('demo:user', ['--fresh' => true])->assertSuccessful();

    expect(Account::ownedBy($other)->count())->toBe(1);
});

it('refuses to run in production', function () {
    app()->detectEnvironment(fn () => 'production');

    artisan('demo:user')->assertFailed();
    expect(User::where('email', 'demo@example.test')->exists())->toBeFalse();
});
