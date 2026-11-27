<?php

use App\Jobs\CheckSavingsOverdrawn;
use App\Models\Account;
use App\Models\Category;
use App\Models\Import;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Notifications\SavingsOverdrawn;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/*
| Rem "tabungan terpakai": saldo rekening tabungan turun di bawah Σ alokasi → satu notifikasi per
| kejadian masuk kondisi (flag savings_overdrawn_at), dari jalur tulis saldo mana pun.
*/

/**
 * Rekening tabungan saldo 20jt, tujuan memegang 15jt, kategori pengeluaran.
 *
 * @return array{0: User, 1: Account, 2: SavingsGoal, 3: Category}
 */
function overdrawnFixture(): array
{
    $user = User::factory()->create();
    $account = Account::factory()->forUser($user)->savings()->opening(20_000_000)->create(['name' => 'BCA Tabungan']);
    $goal = SavingsGoal::factory()->forAccount($account)->allocated(15_000_000)->create(['name' => 'Dana Darurat']);
    $category = Category::factory()->forUser($user)->create(['name' => 'Belanja']);

    return [$user, $account, $goal, $category];
}

function spendFrom(User $user, Account $account, Category $category, int $amount): void
{
    actingAs($user)->postJson('/api/transactions', [
        'account_id' => $account->id, 'type' => 'expense', 'amount' => $amount, 'occurred_on' => '2026-11-27',
        'category_id' => $category->id, 'client_id' => (string) Str::uuid(),
    ])->assertCreated();
}

/** @return list<array<string, mixed>> data notifikasi terpakai milik user, urut shortfall */
function overdrawnNotifications(User $user): array
{
    return DatabaseNotification::query()
        ->where('notifiable_id', $user->id)->where('type', SavingsOverdrawn::TYPE)
        ->get()
        ->map(fn (DatabaseNotification $n) => $n->data)
        ->sortBy('shortfall')->values()->all(); // created_at bisa sama detiknya
}

it('notifies once when an ordinary expense eats into the allocation', function () {
    [$user, $account, , $category] = overdrawnFixture();

    spendFrom($user, $account, $category, 6_000_000); // saldo 14jt < alokasi 15jt

    expect(overdrawnNotifications($user))->toBe([[
        'account_id' => $account->id, 'name' => 'BCA Tabungan',
        'balance' => 14_000_000, 'allocated' => 15_000_000, 'shortfall' => 1_000_000,
    ]])->and($account->fresh()?->savings_overdrawn_at)->not->toBeNull();

    spendFrom($user, $account, $category, 500_000); // masih terpakai → tidak dobel

    expect(overdrawnNotifications($user))->toHaveCount(1);
});

it('notifies again after the account was made healthy by reducing an allocation', function () {
    [$user, $account, $goal, $category] = overdrawnFixture();
    spendFrom($user, $account, $category, 6_500_000); // saldo 13,5jt, terpakai 1,5jt

    actingAs($user)->postJson("/api/savings-goals/{$goal->id}/allocations", [
        'amount' => -2_000_000, 'occurred_on' => '2026-11-27', 'client_id' => (string) Str::uuid(),
    ])->assertCreated(); // alokasi 13jt ≤ saldo → sehat, flag di-reset

    expect($account->fresh()?->savings_overdrawn_at)->toBeNull()
        ->and(overdrawnNotifications($user))->toHaveCount(1);

    spendFrom($user, $account, $category, 1_000_000); // saldo 12,5jt, terpakai 0,5jt

    expect(array_column(overdrawnNotifications($user), 'shortfall'))->toBe([500_000, 1_500_000]);
    assertSavingsBalanced();
});

it('notifies when a transfer out of the savings account eats into the allocation', function () {
    [$user, $account] = overdrawnFixture();
    $cash = Account::factory()->forUser($user)->cash()->create();

    actingAs($user)->postJson('/api/transfers', [
        'from_account_id' => $account->id, 'to_account_id' => $cash->id, 'amount' => 7_000_000,
        'occurred_on' => '2026-11-27', 'client_id' => (string) Str::uuid(),
    ])->assertCreated();

    expect(array_column(overdrawnNotifications($user), 'shortfall'))->toBe([2_000_000]);
});

it('notifies when an import commit lowers the savings balance', function () {
    Storage::fake(Import::DISK);
    [$user, $account, , $category] = overdrawnFixture();

    $importId = (int) actingAs($user)->post('/api/imports', [
        'account_id' => $account->id,
        'format' => 'generic',
        'column_mapping' => json_encode([
            'date' => 'Tanggal', 'date_format' => 'd/m/Y', 'description' => 'Keterangan', 'debit' => 'Debet', 'credit' => 'Kredit',
        ]),
        'file' => UploadedFile::fake()->createWithContent('mutasi.csv', "Tanggal;Keterangan;Debet;Kredit\n20/11/2026;TOKO ELEKTRONIK;8.000.000,00;\n"),
    ], ['Accept' => 'application/json'])->assertAccepted()->assertJsonPath('data.status', 'ready')->json('data.id');
    $rowId = Import::ownedBy($user)->findOrFail($importId)->rows()->value('id');

    actingAs($user)->postJson("/api/imports/{$importId}/commit", [
        'rows' => [['id' => $rowId, 'category_id' => $category->id]],
    ])->assertOk();

    expect($account->fresh()?->balance)->toBe(12_000_000)
        ->and(array_column(overdrawnNotifications($user), 'shortfall'))->toBe([3_000_000]);
});

it('notifies when the balance is adjusted down below the allocation', function () {
    [$user, $account] = overdrawnFixture();

    actingAs($user)->postJson("/api/accounts/{$account->id}/adjust", ['actual_balance' => 11_000_000, 'occurred_on' => '2026-11-27'])
        ->assertCreated();

    expect(array_column(overdrawnNotifications($user), 'shortfall'))->toBe([4_000_000]);
});

it('stays quiet when "Gunakan" spends a fully allocated account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->forUser($user)->savings()->opening(10_000_000)->create();
    $goal = SavingsGoal::factory()->forAccount($account)->allocated(10_000_000)->create();
    $category = Category::factory()->forUser($user)->create();

    // Di dalam transaksi DB saldo turun lebih dulu dari alokasi; job yang jalan sebelum commit akan
    // melihat terpakai sesaat. ShouldQueueAfterCommit → yang terlihat keadaan akhir yang sehat.
    actingAs($user)->postJson("/api/savings-goals/{$goal->id}/use", [
        'amount' => 4_000_000, 'category_id' => $category->id, 'occurred_on' => '2026-11-27', 'client_id' => (string) Str::uuid(),
    ])->assertCreated();

    expect(overdrawnNotifications($user))->toBe([])
        ->and($account->fresh()?->savings_overdrawn_at)->toBeNull();
});

it('works without a logged-in user', function () {
    [$user, $account] = overdrawnFixture();
    // Saldo turun tanpa event model (seperti accounts:reconcile --fix) lalu job dijalankan dari worker.
    Account::query()->withoutGlobalScopes()->whereKey($account->id)->update(['balance' => 10_000_000]);
    app('auth')->forgetGuards();

    (new CheckSavingsOverdrawn($account->id))->handle();

    expect(array_column(overdrawnNotifications($user), 'shortfall'))->toBe([5_000_000]);
});

it('ignores accounts that are not savings accounts', function () {
    $user = User::factory()->create();
    $account = Account::factory()->forUser($user)->opening(1_000_000)->create();
    $category = Category::factory()->forUser($user)->create();

    spendFrom($user, $account, $category, 3_000_000); // saldo minus, tapi bukan rekening tabungan
    (new CheckSavingsOverdrawn($account->id))->handle();

    expect(overdrawnNotifications($user))->toBe([])
        ->and($account->fresh()?->savings_overdrawn_at)->toBeNull();
});
