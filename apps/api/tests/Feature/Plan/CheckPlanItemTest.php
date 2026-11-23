<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\travelTo;

/**
 * @return array{user: User, bca: Account, cash: Account, bills: Category, food: Category, salary: Category, item: PlanItem}
 */
function checkSetup(): array
{
    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);
    $bca = Account::factory()->forUser($user)->opening(5_000_000)->create(['name' => 'BCA']);
    $bills = Category::factory()->forUser($user)->create(['name' => 'Tagihan']);

    return [
        'user' => $user,
        'bca' => $bca,
        'cash' => Account::factory()->forUser($user)->cash()->opening(300_000)->create(['name' => 'Dompet']),
        'bills' => $bills,
        'food' => Category::factory()->forUser($user)->create(['name' => 'Makan']),
        'salary' => Category::factory()->forUser($user)->income()->create(['name' => 'Gaji']),
        'item' => PlanItem::factory()->forUser($user)->create([
            'name' => 'Listrik', 'category_id' => $bills->id, 'planned_amount' => 500_000, 'account_id' => $bca->id, 'due_on' => '2026-11-20',
        ]),
    ];
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function check(User $user, PlanItem $item, array $body = []): TestResponse
{
    return actingAs($user)->postJson("/api/plan-items/{$item->id}/check", $body + ['client_id' => (string) Str::uuid()]);
}

function assertReconcileZero(): void
{
    artisan('accounts:reconcile --no-notify')->expectsOutputToContain('Selisih 0')->assertSuccessful();
}

describe('check', function () {
    it('creates one linked transaction with the item’s defaults and moves the balance', function () {
        $s = checkSetup();
        travelTo('2026-11-21 16:30:00'); // UTC → 23.30 WIB: tetap tanggal 21 di zona user

        check($s['user'], $s['item'])
            ->assertCreated()
            ->assertJsonPath('data.item.status', 'done')
            ->assertJsonPath('data.item.transaction.amount', -500_000)
            ->assertJsonPath('data.transaction.type', 'expense')
            ->assertJsonPath('data.transaction.amount', -500_000)
            ->assertJsonPath('data.transaction.account_id', $s['bca']->id)
            ->assertJsonPath('data.transaction.category_id', $s['bills']->id)
            ->assertJsonPath('data.transaction.plan_item_id', $s['item']->id)
            ->assertJsonPath('data.transaction.occurred_on', '2026-11-21')
            ->assertJsonPath('data.transaction.note', 'Listrik');

        expect($s['bca']->fresh()?->balance)->toBe(4_500_000)
            ->and(Transaction::count())->toBe(1);
        assertReconcileZero();
    });

    it('accepts a different actual amount, account, date and note', function () {
        $s = checkSetup();

        check($s['user'], $s['item'], ['amount' => 487_500, 'account_id' => $s['cash']->id, 'occurred_on' => '2026-11-18', 'note' => 'Token listrik'])
            ->assertCreated()
            ->assertJsonPath('data.transaction.amount', -487_500)
            ->assertJsonPath('data.transaction.account_id', $s['cash']->id)
            ->assertJsonPath('data.transaction.occurred_on', '2026-11-18')
            ->assertJsonPath('data.transaction.note', 'Token listrik')
            ->assertJsonPath('data.item.planned_amount', 500_000); // rencana tidak berubah

        expect([$s['bca']->fresh()?->balance, $s['cash']->fresh()?->balance])->toBe([5_000_000, -187_500]);
        assertReconcileZero();
    });

    it('books income items as positive amounts', function () {
        $s = checkSetup();
        $salary = PlanItem::factory()->forUser($s['user'])->create(['type' => 'income', 'name' => 'Gaji', 'category_id' => $s['salary']->id, 'planned_amount' => 8_000_000, 'account_id' => $s['bca']->id]);

        check($s['user'], $salary)->assertCreated()->assertJsonPath('data.transaction.amount', 8_000_000)->assertJsonPath('data.transaction.type', 'income');

        expect($s['bca']->fresh()?->balance)->toBe(13_000_000);
    });

    it('answers a retry with the same client_id with 200 and the same transaction', function () {
        $s = checkSetup();
        $clientId = (string) Str::uuid();

        $first = check($s['user'], $s['item'], ['client_id' => $clientId])->assertCreated();
        check($s['user'], $s['item'], ['client_id' => strtoupper($clientId)])
            ->assertOk()
            ->assertJsonPath('data.transaction.id', $first->json('data.transaction.id'));

        expect(Transaction::count())->toBe(1)->and($s['bca']->fresh()?->balance)->toBe(4_500_000);
    });

    it('answers a second check with another client_id with 409 and changes nothing', function () {
        $s = checkSetup();
        check($s['user'], $s['item'])->assertCreated();

        check($s['user'], $s['item'])->assertConflict()->assertJsonPath('message', fn ($m) => str_contains($m, 'sudah dicentang'));

        expect(Transaction::count())->toBe(1)->and($s['bca']->fresh()?->balance)->toBe(4_500_000);
    });

    it('refuses envelopes (422) and skipped items (409)', function () {
        $s = checkSetup();
        $envelope = PlanItem::factory()->forUser($s['user'])->envelope()->create(['category_id' => $s['food']->id, 'account_id' => $s['bca']->id]);
        $skipped = PlanItem::factory()->forUser($s['user'])->create(['category_id' => $s['bills']->id, 'account_id' => $s['bca']->id, 'skipped_at' => now()]);

        check($s['user'], $envelope)->assertUnprocessable()->assertJsonValidationErrors('mode');
        check($s['user'], $skipped)->assertConflict();

        expect(Transaction::count())->toBe(0);
    });

    it('validates the account and client_id', function (string $case, string $field) {
        $s = checkSetup();
        [$item, $body] = match ($case) {
            'no account at all' => [PlanItem::factory()->forUser($s['user'])->create(['category_id' => $s['bills']->id, 'account_id' => null]), []],
            'archived account' => [$s['item'], ['account_id' => Account::factory()->forUser($s['user'])->archived()->create()->id]],
            'missing client_id' => [$s['item'], ['client_id' => '']],
            'zero amount' => [$s['item'], ['amount' => 0]],
            'string amount' => [$s['item'], ['amount' => '500.000']],
            default => throw new LogicException($case),
        };

        actingAs($s['user'])->postJson("/api/plan-items/{$item->id}/check", $body + ($case === 'missing client_id' ? [] : ['client_id' => (string) Str::uuid()]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect(Transaction::count())->toBe(0);
    })->with([
        ['no account at all', 'account_id'],
        ['archived account', 'account_id'],
        ['missing client_id', 'client_id'],
        ['zero amount', 'amount'],
        ['string amount', 'amount'],
    ]);

    it('refuses a client_id that already belongs to another transaction', function () {
        $s = checkSetup();
        $clientId = (string) Str::uuid();
        actingAs($s['user'])->postJson('/api/transactions', [
            'account_id' => $s['bca']->id, 'type' => 'expense', 'amount' => 1_000, 'occurred_on' => '2026-11-21', 'client_id' => $clientId,
        ])->assertCreated();

        check($s['user'], $s['item'], ['client_id' => $clientId])->assertUnprocessable()->assertJsonValidationErrors('client_id');
        expect($s['item']->isDone())->toBeFalse();
    });
});

describe('uncheck', function () {
    it('soft deletes the transaction, releases plan_item_id and restores the balance', function () {
        $s = checkSetup();
        $txId = check($s['user'], $s['item'])->json('data.transaction.id');

        actingAs($s['user'])->postJson("/api/plan-items/{$s['item']->id}/uncheck")
            ->assertOk()
            ->assertJsonPath('data.status', 'planned')
            ->assertJsonPath('data.transaction', null);

        $tx = Transaction::withTrashed()->findOrFail($txId);
        expect($tx->trashed())->toBeTrue()
            ->and($tx->plan_item_id)->toBeNull()
            ->and($s['bca']->fresh()?->balance)->toBe(5_000_000);
        assertReconcileZero();
    });

    it('can be checked again after unchecking (unique + soft delete), repeatedly', function () {
        $s = checkSetup();

        foreach ([500_000, 480_000, 510_000] as $amount) {
            check($s['user'], $s['item'], ['amount' => $amount])->assertCreated();
            actingAs($s['user'])->postJson("/api/plan-items/{$s['item']->id}/uncheck")->assertOk();
        }
        check($s['user'], $s['item'], ['amount' => 499_000])->assertCreated();

        expect(Transaction::count())->toBe(1)
            ->and(Transaction::withTrashed()->count())->toBe(4)
            ->and($s['bca']->fresh()?->balance)->toBe(4_501_000);
        assertReconcileZero();
    });

    it('is idempotent on an unchecked item', function () {
        $s = checkSetup();

        actingAs($s['user'])->postJson("/api/plan-items/{$s['item']->id}/uncheck")->assertOk()->assertJsonPath('data.status', 'planned');

        expect($s['bca']->fresh()?->balance)->toBe(5_000_000);
    });

    it('lets the item be deleted once unchecked', function () {
        $s = checkSetup();
        check($s['user'], $s['item'])->assertCreated();
        actingAs($s['user'])->deleteJson("/api/plan-items/{$s['item']->id}")->assertConflict();

        actingAs($s['user'])->postJson("/api/plan-items/{$s['item']->id}/uncheck")->assertOk();
        actingAs($s['user'])->deleteJson("/api/plan-items/{$s['item']->id}")->assertNoContent();
    });
});

describe('linked transaction on the transactions page', function () {
    it('deleting it unchecks the item, and the item can be checked again', function () {
        $s = checkSetup();
        $txId = check($s['user'], $s['item'])->json('data.transaction.id');

        actingAs($s['user'])->deleteJson("/api/transactions/{$txId}")->assertNoContent();

        actingAs($s['user'])->getJson("/api/plan-items/{$s['item']->id}")->assertJsonPath('data.status', 'planned');
        check($s['user'], $s['item'])->assertCreated();
        assertReconcileZero();
    });

    it('allows editing amount, date and account, and the item shows the new actual', function () {
        $s = checkSetup();
        $txId = check($s['user'], $s['item'])->json('data.transaction.id');

        actingAs($s['user'])->patchJson("/api/transactions/{$txId}", ['amount' => 520_000, 'account_id' => $s['cash']->id, 'occurred_on' => '2026-11-22'])->assertOk();

        actingAs($s['user'])->getJson("/api/plan-items/{$s['item']->id}")
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.transaction.amount', -520_000)
            ->assertJsonPath('data.transaction.account_id', $s['cash']->id);
        assertReconcileZero();
    });

    it('refuses changing its type or category (unchecking first is required)', function () {
        $s = checkSetup();
        $txId = check($s['user'], $s['item'])->json('data.transaction.id');

        actingAs($s['user'])->patchJson("/api/transactions/{$txId}", ['category_id' => $s['food']->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['category_id' => 'Batalkan centang']);
        actingAs($s['user'])->patchJson("/api/transactions/{$txId}", ['type' => 'income'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        expect($s['bca']->fresh()?->balance)->toBe(4_500_000);
    });
});
