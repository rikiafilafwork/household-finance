<?php

use App\Enums\HouseholdRole;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * @return array{0: Household, 1: User, 2: User}
 */
function budgetHousehold(): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();
    $household->members()->attach($member, ['role' => HouseholdRole::Member, 'joined_at' => now()]);

    return [$household, $owner, $member];
}

function budgetsUrl(Household $household, string $month, string $suffix = ''): string
{
    return "/api/households/{$household->id}/budgets/{$month}{$suffix}";
}

function spend(User $actor, Household $household, Wallet $wallet, ?Category $category, int $amount, string $on, string $type = 'expense'): int
{
    return actingAs($actor)->postJson("/api/households/{$household->id}/transactions", [
        'wallet_id' => $wallet->id,
        'category_id' => $category?->id,
        'type' => $type,
        'amount' => $amount,
        'occurred_on' => $on,
        'client_id' => (string) Str::uuid(),
    ])->assertCreated()->json('data.id');
}

describe('schema', function () {
    it('enforces month = day 1, positive limit, and one budget per category per month', function (array $override) {
        $budget = Budget::factory()->forMonth('2026-11')->create();

        expect(fn () => DB::table('budgets')->insert([
            'household_id' => $budget->household_id,
            'category_id' => Category::factory()->create(['household_id' => $budget->household_id])->id,
            'month' => '2026-12-01',
            'limit_amount' => 100,
            ...$override,
            ...(isset($override['category_id']) ? ['category_id' => $budget->category_id] : []),
        ]))->toThrow(QueryException::class);
    })->with([
        'tanggal 15' => [['month' => '2026-12-15']],
        'limit 0' => [['limit_amount' => 0]],
        'limit negatif' => [['limit_amount' => -1]],
        'duplikat kategori+bulan' => [['category_id' => true, 'month' => '2026-11-01']],
    ]);

    it('allows each threshold alert only once', function () {
        $budget = Budget::factory()->create();
        $row = ['budget_id' => $budget->id, 'threshold' => 80, 'spent_amount' => 800, 'triggered_at' => now()];

        DB::table('budget_alerts')->insert($row);
        expect(fn () => DB::table('budget_alerts')->insert($row))->toThrow(QueryException::class)
            ->and(fn () => DB::table('budget_alerts')->insert([...$row, 'threshold' => 50]))->toThrow(QueryException::class);
    });
});

describe('PUT /budgets/{month}', function () {
    it('sets the whole month in one batch and replaces it on the next call', function () {
        [$household, $owner] = budgetHousehold();
        [$food, $transport, $fun] = Category::factory()->for($household)->count(3)->sequence(['name' => 'Makan'], ['name' => 'Transport'], ['name' => 'Hiburan'])->create();

        actingAs($owner)->putJson(budgetsUrl($household, '2026-11'), ['items' => [
            ['category_id' => $food->id, 'limit_amount' => 3_000_000],
            ['category_id' => $transport->id, 'limit_amount' => 1_000_000],
        ]])
            ->assertOk()
            ->assertJsonPath('data.month', '2026-11')
            ->assertJsonPath('data.items.*.category.name', ['Makan', 'Transport'])
            ->assertJsonPath('data.totals.limit_amount', 4_000_000);

        $foodBudgetId = Budget::where('category_id', $food->id)->value('id');

        actingAs($owner)->putJson(budgetsUrl($household, '2026-11'), ['items' => [
            ['category_id' => $food->id, 'limit_amount' => 2_500_000],
            ['category_id' => $fun->id, 'limit_amount' => 500_000],
        ]])
            ->assertOk()
            ->assertJsonPath('data.items.*.category.name', ['Hiburan', 'Makan'])
            ->assertJsonPath('data.totals.limit_amount', 3_000_000);

        // Baris lama dipertahankan (id tetap → riwayat alert tidak hilang), transport dihapus.
        expect(Budget::where('category_id', $food->id)->value('id'))->toBe($foodBudgetId)
            ->and(Budget::where('category_id', $transport->id)->exists())->toBeFalse()
            ->and(Budget::first()?->month->toDateString())->toBe('2026-11-01');

        actingAs($owner)->putJson(budgetsUrl($household, '2026-11'), ['items' => []])->assertOk()->assertJsonCount(0, 'data.items');
        expect(Budget::count())->toBe(0);
    });

    it('does not touch other months or other households', function () {
        [$household, $owner] = budgetHousehold();
        $food = Category::factory()->for($household)->create();
        $october = Budget::factory()->for($household)->create(['category_id' => $food->id, 'month' => '2026-10-01']);
        $foreign = Budget::factory()->forMonth('2026-11')->create();

        actingAs($owner)->putJson(budgetsUrl($household, '2026-11'), ['items' => []])->assertOk();

        expect($october->fresh())->not->toBeNull()->and($foreign->fresh())->not->toBeNull();
    });

    it('validates items', function (Closure $items, string $error) {
        [$household, $owner] = budgetHousehold();
        $food = Category::factory()->for($household)->create();
        $salary = Category::factory()->for($household)->income()->create();
        $archived = Category::factory()->for($household)->archived()->create();
        $foreign = Category::factory()->create();

        actingAs($owner)->putJson(budgetsUrl($household, '2026-11'), ['items' => $items($food, $salary, $archived, $foreign)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($error);
        expect(Budget::count())->toBe(0);
    })->with([
        'limit string' => [fn ($f) => [['category_id' => $f->id, 'limit_amount' => '1000000']], 'items.0.limit_amount'],
        'limit float' => [fn ($f) => [['category_id' => $f->id, 'limit_amount' => 1000.5]], 'items.0.limit_amount'],
        'limit 0' => [fn ($f) => [['category_id' => $f->id, 'limit_amount' => 0]], 'items.0.limit_amount'],
        'kategori dobel' => [fn ($f) => [['category_id' => $f->id, 'limit_amount' => 1], ['category_id' => $f->id, 'limit_amount' => 2]], 'items.0.category_id'],
        'kategori pemasukan' => [fn ($f, $s) => [['category_id' => $s->id, 'limit_amount' => 1]], 'items.0.category_id'],
        'kategori diarsip' => [fn ($f, $s, $a) => [['category_id' => $a->id, 'limit_amount' => 1]], 'items.0.category_id'],
        'kategori household lain' => [fn ($f, $s, $a, $x) => [['category_id' => $x->id, 'limit_amount' => 1]], 'items.0.category_id'],
        'tanpa items' => [fn () => null, 'items'],
    ]);

    it('keeps an existing budget of a category archived later', function () {
        [$household, $owner] = budgetHousehold();
        $food = Category::factory()->for($household)->create();
        Budget::factory()->for($household)->forMonth('2026-11')->create(['category_id' => $food->id]);
        $food->update(['archived_at' => now()]);

        actingAs($owner)->putJson(budgetsUrl($household, '2026-11'), ['items' => [['category_id' => $food->id, 'limit_amount' => 9]]])
            ->assertOk()->assertJsonPath('data.items.0.limit_amount', 9);
    });

    it('rejects invalid months with 404', function (string $month) {
        [$household, $owner] = budgetHousehold();

        actingAs($owner)->getJson(budgetsUrl($household, $month))->assertNotFound();
    })->with(['2026-13', '2026-00', '2026-1', '26-11', '2026-11-01']);
});

describe('roles', function () {
    it('lets members view but not change budgets; the owner can', function () {
        [$household, $owner, $member] = budgetHousehold();
        $food = Category::factory()->for($household)->create();
        $items = ['items' => [['category_id' => $food->id, 'limit_amount' => 100_000]]];

        actingAs($member)->putJson(budgetsUrl($household, '2026-11'), $items)->assertForbidden();
        actingAs($member)->postJson(budgetsUrl($household, '2026-11', '/copy-from-previous'))->assertForbidden();
        expect(Budget::count())->toBe(0);

        actingAs($owner)->putJson(budgetsUrl($household, '2026-11'), $items)->assertOk();
        actingAs($member)->getJson(budgetsUrl($household, '2026-11'))->assertOk()->assertJsonPath('data.items.0.limit_amount', 100_000);
    });

    it('hides budgets from outsiders', function () {
        [$household] = budgetHousehold();

        actingAs(User::factory()->create())->getJson(budgetsUrl($household, '2026-11'))->assertForbidden();
    });
});

describe('POST /budgets/{month}/copy-from-previous', function () {
    it('copies last month limits without overwriting this month or archived categories', function () {
        [$household, $owner] = budgetHousehold();
        [$food, $transport, $old] = Category::factory()->for($household)->count(3)->sequence(['name' => 'Makan'], ['name' => 'Transport'], ['name' => 'Lama'])->create();
        Budget::factory()->for($household)->forMonth('2026-10')->create(['category_id' => $food->id, 'limit_amount' => 3_000_000]);
        Budget::factory()->for($household)->forMonth('2026-10')->create(['category_id' => $transport->id, 'limit_amount' => 1_000_000]);
        Budget::factory()->for($household)->forMonth('2026-10')->create(['category_id' => $old->id, 'limit_amount' => 50_000]);
        Budget::factory()->for($household)->forMonth('2026-11')->create(['category_id' => $transport->id, 'limit_amount' => 1_200_000]);
        $old->update(['archived_at' => now()]);

        actingAs($owner)->postJson(budgetsUrl($household, '2026-11', '/copy-from-previous'))
            ->assertOk()
            ->assertJsonPath('meta.copied', 1)
            ->assertJsonPath('data.items.*.category.name', ['Makan', 'Transport'])
            ->assertJsonPath('data.items.*.limit_amount', [3_000_000, 1_200_000]);

        actingAs($owner)->postJson(budgetsUrl($household, '2026-11', '/copy-from-previous'))->assertJsonPath('meta.copied', 0);
        expect(Budget::where('month', '2026-11-01')->count())->toBe(2);
    });

    it('rolls January back to December of the previous year', function () {
        [$household, $owner] = budgetHousehold();
        $food = Category::factory()->for($household)->create();
        Budget::factory()->for($household)->forMonth('2026-12')->create(['category_id' => $food->id]);

        actingAs($owner)->postJson(budgetsUrl($household, '2027-01', '/copy-from-previous'))->assertJsonPath('meta.copied', 1);
    });

    it('copies from February into March without overflow (month-end safe)', function () {
        [$household, $owner] = budgetHousehold();
        $food = Category::factory()->for($household)->create();
        Budget::factory()->for($household)->forMonth('2027-02')->create(['category_id' => $food->id]);

        actingAs($owner)->postJson(budgetsUrl($household, '2027-03', '/copy-from-previous'))->assertJsonPath('meta.copied', 1);
    });
});

describe('GET /budgets/{month} (acceptance: spent matches a manual count)', function () {
    it('counts only posted, non-deleted expenses of shared wallets in that month and category', function () {
        [$household, $owner, $member] = budgetHousehold();
        $bca = Wallet::factory()->for($household)->opening(50_000_000)->create();
        $cash = Wallet::factory()->for($household)->cash()->opening(5_000_000)->create();
        $memberPrivate = Wallet::factory()->for($household)->privateTo($member)->opening(5_000_000)->create();
        $food = Category::factory()->for($household)->create(['name' => 'Makan']);
        $transport = Category::factory()->for($household)->create(['name' => 'Transport']);
        $other = Category::factory()->for($household)->create(['name' => 'Lainnya']);
        $salary = Category::factory()->for($household)->income()->create();

        actingAs($owner)->putJson(budgetsUrl($household, '2026-11'), ['items' => [
            ['category_id' => $food->id, 'limit_amount' => 1_000_000],
            ['category_id' => $transport->id, 'limit_amount' => 300_000],
        ]])->assertOk();

        // Dihitung (Makan):
        spend($owner, $household, $bca, $food, 250_000, '2026-11-01');   // hari pertama
        spend($member, $household, $cash, $food, 125_500, '2026-11-15'); // member, wallet shared lain
        spend($owner, $household, $bca, $food, 80_000, '2026-11-30');    // hari terakhir
        // Dihitung (Transport):
        spend($member, $household, $bca, $transport, 330_000, '2026-11-10'); // lewat limit

        // TIDAK dihitung:
        spend($owner, $household, $bca, $food, 999_000, '2026-10-31');           // bulan lalu
        spend($owner, $household, $bca, $food, 999_000, '2026-12-01');           // bulan depan
        spend($member, $household, $memberPrivate, $food, 777_000, '2026-11-12'); // wallet privat
        spend($owner, $household, $bca, $salary, 9_000_000, '2026-11-25', 'income');
        $deleted = spend($owner, $household, $bca, $food, 444_000, '2026-11-20');
        actingAs($owner)->deleteJson("/api/households/{$household->id}/transactions/{$deleted}")->assertNoContent();
        Transaction::factory()->for($household)->forWallet($bca)->expense(555_000)->pending()
            ->create(['category_id' => $food->id, 'occurred_on' => '2026-11-21']); // pending
        actingAs($owner)->postJson("/api/households/{$household->id}/transfers", [
            'from_wallet_id' => $bca->id, 'to_wallet_id' => $cash->id, 'amount' => 600_000,
            'occurred_on' => '2026-11-05', 'client_id' => (string) Str::uuid(),
        ])->assertCreated();
        actingAs($owner)->postJson("/api/households/{$household->id}/wallets/{$cash->id}/adjust", [
            'actual_balance' => 1_000, 'occurred_on' => '2026-11-06',
        ])->assertCreated();
        // Tanpa budget, tapi dihitung sebagai "unbudgeted":
        spend($owner, $household, $bca, $other, 42_000, '2026-11-07');
        spend($owner, $household, $bca, null, 8_000, '2026-11-08'); // expense tanpa kategori: tidak masuk mana pun

        $manualFood = 250_000 + 125_500 + 80_000; // 455.500

        foreach ([$owner, $member] as $viewer) {
            actingAs($viewer)->getJson(budgetsUrl($household, '2026-11'))
                ->assertOk()
                ->assertJsonPath('data.items.0.category.name', 'Makan')
                ->assertJsonPath('data.items.0.limit_amount', 1_000_000)
                ->assertJsonPath('data.items.0.spent', $manualFood)
                ->assertJsonPath('data.items.0.remaining', 1_000_000 - $manualFood)
                ->assertJsonPath('data.items.0.percent', 45) // 45,55% → dibulatkan ke bawah
                ->assertJsonPath('data.items.1.category.name', 'Transport')
                ->assertJsonPath('data.items.1.spent', 330_000)
                ->assertJsonPath('data.items.1.remaining', -30_000)
                ->assertJsonPath('data.items.1.percent', 110)
                ->assertJsonPath('data.totals.limit_amount', 1_300_000)
                ->assertJsonPath('data.totals.spent', $manualFood + 330_000)
                ->assertJsonPath('data.totals.remaining', 1_300_000 - $manualFood - 330_000)
                ->assertJsonPath('data.totals.percent', 60) // 785.500 / 1.300.000 = 60,4%
                ->assertJsonPath('data.totals.unbudgeted_spent', 42_000);
        }
    });

    it('floors the percent so 79.99% is 79 and not 80', function () {
        [$household, $owner] = budgetHousehold();
        $bca = Wallet::factory()->for($household)->opening(10_000_000)->create();
        $food = Category::factory()->for($household)->create();
        Budget::factory()->for($household)->forMonth('2026-11')->create(['category_id' => $food->id, 'limit_amount' => 1_000_000]);

        spend($owner, $household, $bca, $food, 799_999, '2026-11-02');

        actingAs($owner)->getJson(budgetsUrl($household, '2026-11'))->assertJsonPath('data.items.0.percent', 79);
    });

    it('returns an empty month with zero totals', function () {
        [$household, $owner] = budgetHousehold();

        actingAs($owner)->getJson(budgetsUrl($household, '2026-11'))
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.totals.limit_amount', 0)
            ->assertJsonPath('data.totals.percent', null);
    });

    it('refuses to delete a category that has a budget', function () {
        [$household, $owner] = budgetHousehold();
        $food = Category::factory()->for($household)->create();
        Budget::factory()->for($household)->create(['category_id' => $food->id]);

        actingAs($owner)->deleteJson("/api/households/{$household->id}/categories/{$food->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('category');
    });
});
