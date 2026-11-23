<?php

use App\Enums\PlanItemSource;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * @return array{user: User, bca: Account, food: Category, bills: Category, salary: Category}
 */
function planSetup(): array
{
    $user = User::factory()->create();

    return [
        'user' => $user,
        'bca' => Account::factory()->forUser($user)->opening(5_000_000)->create(['name' => 'BCA']),
        'food' => Category::factory()->forUser($user)->create(['name' => 'Makan']),
        'bills' => Category::factory()->forUser($user)->create(['name' => 'Tagihan']),
        'salary' => Category::factory()->forUser($user)->income()->create(['name' => 'Gaji']),
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function checklistPayload(Category $category, array $overrides = []): array
{
    return array_merge(['type' => 'expense', 'mode' => 'checklist', 'name' => 'Listrik', 'category_id' => $category->id, 'planned_amount' => 500_000], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function envelopePayload(Category $category, array $overrides = []): array
{
    return array_merge(['type' => 'expense', 'mode' => 'envelope', 'name' => 'Makan', 'category_id' => $category->id, 'planned_amount' => 2_000_000], $overrides);
}

/** Tandai item sudah dicentang (CheckPlanItem menyusul di #126; di sini cukup baris terhubung). */
function markDone(PlanItem $item, Account $account, int $amount = 500_000): Transaction
{
    return Transaction::factory()->forAccount($account)->expense($amount)->create([
        'category_id' => $item->category_id, 'plan_item_id' => $item->id, 'occurred_on' => '2026-11-10',
    ]);
}

describe('creating and listing', function () {
    it('adds a checklist item and an envelope to a month', function () {
        $s = planSetup();

        actingAs($s['user'])->postJson('/api/plan/2026-11/items', checklistPayload($s['bills'], ['account_id' => $s['bca']->id, 'due_on' => '2026-11-20']))
            ->assertCreated()
            ->assertJsonPath('data.month', '2026-11')
            ->assertJsonPath('data.mode', 'checklist')
            ->assertJsonPath('data.planned_amount', 500_000)
            ->assertJsonPath('data.account_id', $s['bca']->id)
            ->assertJsonPath('data.due_on', '2026-11-20')
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.status', 'planned')
            ->assertJsonPath('data.transaction', null);

        actingAs($s['user'])->postJson('/api/plan/2026-11/items', envelopePayload($s['food']))
            ->assertCreated()
            ->assertJsonPath('data.mode', 'envelope')
            ->assertJsonPath('data.due_on', null);

        expect(PlanItem::pluck('month')->map->toDateString()->all())->toBe(['2026-11-01', '2026-11-01']);
    });

    it('lists only the requested month, by sort order then due date', function () {
        $s = planSetup();
        $late = PlanItem::factory()->forUser($s['user'])->create(['name' => 'Internet', 'category_id' => $s['bills']->id, 'due_on' => '2026-11-25']);
        $early = PlanItem::factory()->forUser($s['user'])->create(['name' => 'Cicilan', 'category_id' => $s['bills']->id, 'due_on' => '2026-11-05']);
        $envelope = PlanItem::factory()->forUser($s['user'])->envelope()->create(['name' => 'Makan', 'category_id' => $s['food']->id]);
        PlanItem::factory()->forUser($s['user'])->forMonth('2026-10')->create(['category_id' => $s['bills']->id]);

        actingAs($s['user'])->getJson('/api/plan/2026-11')
            ->assertOk()
            ->assertJsonPath('data.items.*.id', [$early->id, $late->id, $envelope->id])
            ->assertJsonPath('data.items.0.category.name', 'Tagihan');
    });

    it('allows several checklist items in one category but only one envelope per category per month', function () {
        $s = planSetup();
        actingAs($s['user'])->postJson('/api/plan/2026-11/items', checklistPayload($s['bills'], ['name' => 'Listrik']))->assertCreated();
        actingAs($s['user'])->postJson('/api/plan/2026-11/items', checklistPayload($s['bills'], ['name' => 'Internet']))->assertCreated();
        actingAs($s['user'])->postJson('/api/plan/2026-11/items', envelopePayload($s['food']))->assertCreated();

        actingAs($s['user'])->postJson('/api/plan/2026-11/items', envelopePayload($s['food'], ['name' => 'Jajan']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id' => 'sudah punya amplop']);
        // Bulan lain boleh.
        actingAs($s['user'])->postJson('/api/plan/2026-12/items', envelopePayload($s['food']))->assertCreated();
    });

    it('validates the item', function (string $case, string $field) {
        $s = planSetup();
        $payload = match ($case) {
            'kind mismatch' => checklistPayload($s['salary']),
            'archived category' => checklistPayload(Category::factory()->forUser($s['user'])->archived()->create()),
            'archived account' => checklistPayload($s['bills'], ['account_id' => Account::factory()->forUser($s['user'])->archived()->create()->id]),
            'due date in another month' => checklistPayload($s['bills'], ['due_on' => '2026-12-01']),
            'envelope with due date' => envelopePayload($s['food'], ['due_on' => '2026-11-10']),
            'zero amount' => checklistPayload($s['bills'], ['planned_amount' => 0]),
            'float amount' => checklistPayload($s['bills'], ['planned_amount' => 1000.5]),
            'string amount' => checklistPayload($s['bills'], ['planned_amount' => '500.000']),
            'unknown mode' => checklistPayload($s['bills'], ['mode' => 'weekly']),
            'transfer type' => checklistPayload($s['bills'], ['type' => 'transfer']),
            'missing name' => checklistPayload($s['bills'], ['name' => '  ']),
            'client sets month' => checklistPayload($s['bills'], ['month' => '2026-01-01']),
            default => throw new LogicException($case),
        };

        actingAs($s['user'])->postJson('/api/plan/2026-11/items', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect(PlanItem::count())->toBe(0);
    })->with([
        ['kind mismatch', 'category_id'],
        ['archived category', 'category_id'],
        ['archived account', 'account_id'],
        ['due date in another month', 'due_on'],
        ['envelope with due date', 'due_on'],
        ['zero amount', 'planned_amount'],
        ['float amount', 'planned_amount'],
        ['string amount', 'planned_amount'],
        ['unknown mode', 'mode'],
        ['transfer type', 'type'],
        ['missing name', 'name'],
        ['client sets month', 'month'],
    ]);

    it('rejects malformed months in the URL', function (string $month) {
        actingAs(User::factory()->create())->getJson("/api/plan/{$month}")->assertNotFound();
    })->with(['2026-13', '2026-1', '26-11', '2026-11-01']);

    it('requires login', function () {
        getJson('/api/plan/2026-11')->assertUnauthorized();
    });
});

describe('database constraints', function () {
    it('rejects two envelopes for the same category in the same month at the database level', function () {
        $s = planSetup();
        PlanItem::factory()->forUser($s['user'])->envelope()->create(['category_id' => $s['food']->id]);

        PlanItem::factory()->forUser($s['user'])->envelope()->create(['category_id' => $s['food']->id, 'name' => 'Lagi']);
    })->throws(QueryException::class, 'uq_plan_envelope');

    it('enforces the CHECK constraints', function (array $row, string $constraint) {
        $s = planSetup();

        expect(fn () => DB::table('plan_items')->insert(array_merge([
            'user_id' => $s['user']->id, 'month' => '2026-11-01', 'type' => 'expense', 'mode' => 'checklist',
            'name' => 'X', 'category_id' => $s['bills']->id, 'planned_amount' => 1, 'source' => 'manual',
        ], $row)))->toThrow(QueryException::class, $constraint);
    })->with([
        'zero amount' => [['planned_amount' => 0], 'chk_plan_amount'],
        'month not on the 1st' => [['month' => '2026-11-15'], 'chk_plan_month'],
        'envelope with due date' => [['mode' => 'envelope', 'due_on' => '2026-11-10'], 'chk_plan_due'],
    ]);

    it('refuses to delete a plan item that a transaction still points to', function () {
        $s = planSetup();
        $item = PlanItem::factory()->forUser($s['user'])->create(['category_id' => $s['bills']->id]);
        markDone($item, $s['bca']);

        DB::table('plan_items')->where('id', $item->id)->delete();
    })->throws(QueryException::class, 'transactions_plan_item_id_foreign');
});

describe('updating', function () {
    it('updates fields partially and switching to an envelope drops the due date', function () {
        $s = planSetup();
        $item = PlanItem::factory()->forUser($s['user'])->create(['category_id' => $s['food']->id, 'due_on' => '2026-11-10']);

        actingAs($s['user'])->patchJson("/api/plan-items/{$item->id}", ['planned_amount' => 750_000, 'name' => 'Belanja bulanan'])
            ->assertOk()
            ->assertJsonPath('data.planned_amount', 750_000)
            ->assertJsonPath('data.name', 'Belanja bulanan')
            ->assertJsonPath('data.due_on', '2026-11-10');

        actingAs($s['user'])->patchJson("/api/plan-items/{$item->id}", ['mode' => 'envelope'])
            ->assertOk()
            ->assertJsonPath('data.mode', 'envelope')
            ->assertJsonPath('data.due_on', null);
    });

    it('keeps the type, mode and category of a checked item locked, but allows the rest', function () {
        $s = planSetup();
        $item = PlanItem::factory()->forUser($s['user'])->create(['category_id' => $s['bills']->id]);
        markDone($item, $s['bca']);

        actingAs($s['user'])->patchJson("/api/plan-items/{$item->id}", ['category_id' => $s['food']->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['category_id' => 'sudah dicentang']);
        actingAs($s['user'])->patchJson("/api/plan-items/{$item->id}", ['mode' => 'envelope'])
            ->assertUnprocessable()->assertJsonValidationErrors('mode');

        actingAs($s['user'])->patchJson("/api/plan-items/{$item->id}", ['planned_amount' => 650_000, 'due_on' => '2026-11-28'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.planned_amount', 650_000);
    });

    it('keeps an archived category that the item already uses', function () {
        $s = planSetup();
        $item = PlanItem::factory()->forUser($s['user'])->create(['category_id' => $s['bills']->id]);
        $s['bills']->update(['archived_at' => now()]);

        actingAs($s['user'])->patchJson("/api/plan-items/{$item->id}", ['planned_amount' => 1])->assertOk();
    });

    it('rejects turning a checklist item into a second envelope of the same category', function () {
        $s = planSetup();
        PlanItem::factory()->forUser($s['user'])->envelope()->create(['category_id' => $s['food']->id]);
        $item = PlanItem::factory()->forUser($s['user'])->create(['category_id' => $s['food']->id]);

        actingAs($s['user'])->patchJson("/api/plan-items/{$item->id}", ['mode' => 'envelope'])
            ->assertUnprocessable()->assertJsonValidationErrors(['category_id' => 'sudah punya amplop']);
    });
});

describe('status, skip and delete', function () {
    it('derives the status from the linked transaction and skipped_at', function () {
        $s = planSetup();
        $item = PlanItem::factory()->forUser($s['user'])->create(['category_id' => $s['bills']->id]);
        $tx = markDone($item, $s['bca'], 480_000);

        actingAs($s['user'])->getJson("/api/plan-items/{$item->id}")
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.transaction.id', $tx->id)
            ->assertJsonPath('data.transaction.amount', -480_000);

        // Batal centang (UncheckPlanItem, #126) = soft delete + plan_item_id NULL → kembali "planned".
        $tx->forceFill(['plan_item_id' => null])->save();
        $tx->delete();
        actingAs($s['user'])->getJson("/api/plan-items/{$item->id}")->assertJsonPath('data.status', 'planned');
    });

    it('skips and unskips an item, idempotently', function () {
        $s = planSetup();
        $item = PlanItem::factory()->forUser($s['user'])->create(['category_id' => $s['bills']->id]);

        $first = actingAs($s['user'])->postJson("/api/plan-items/{$item->id}/skip")
            ->assertOk()->assertJsonPath('data.status', 'skipped')->json('data.skipped_at');
        actingAs($s['user'])->postJson("/api/plan-items/{$item->id}/skip")->assertJsonPath('data.skipped_at', $first);

        actingAs($s['user'])->postJson("/api/plan-items/{$item->id}/unskip")
            ->assertOk()->assertJsonPath('data.status', 'planned')->assertJsonPath('data.skipped_at', null);
    });

    it('refuses to skip or delete a checked item (409)', function () {
        $s = planSetup();
        $item = PlanItem::factory()->forUser($s['user'])->create(['category_id' => $s['bills']->id]);
        markDone($item, $s['bca']);

        actingAs($s['user'])->postJson("/api/plan-items/{$item->id}/skip")->assertConflict()->assertJsonPath('message', fn ($m) => str_contains($m, 'sudah dicentang'));
        actingAs($s['user'])->deleteJson("/api/plan-items/{$item->id}")->assertConflict()->assertJsonPath('message', fn ($m) => str_contains($m, 'Batalkan centang'));

        expect(PlanItem::whereKey($item->id)->first()?->skipped_at)->toBeNull();
    });

    it('deletes an unchecked item, including one whose old transaction was unchecked', function () {
        $s = planSetup();
        $item = PlanItem::factory()->forUser($s['user'])->create(['category_id' => $s['bills']->id]);
        $tx = markDone($item, $s['bca']);
        $tx->forceFill(['plan_item_id' => null])->save();
        $tx->delete();

        actingAs($s['user'])->deleteJson("/api/plan-items/{$item->id}")->assertNoContent();

        expect(PlanItem::count())->toBe(0);
    });
});

describe('copy from previous month', function () {
    it('copies last month’s items once, clamping due dates to the new month', function () {
        $s = planSetup();
        PlanItem::factory()->forUser($s['user'])->forMonth('2027-01')->create(['name' => 'Cicilan', 'category_id' => $s['bills']->id, 'due_on' => '2027-01-31', 'account_id' => $s['bca']->id, 'sort_order' => 2]);
        PlanItem::factory()->forUser($s['user'])->forMonth('2027-01')->envelope()->create(['name' => 'Makan', 'category_id' => $s['food']->id, 'planned_amount' => 2_000_000]);
        PlanItem::factory()->forUser($s['user'])->forMonth('2027-01')->create(['name' => 'Asuransi', 'category_id' => $s['bills']->id, 'skipped_at' => now()]);

        actingAs($s['user'])->postJson('/api/plan/2027-02/copy-from-previous')->assertCreated()->assertJsonPath('data.created', 3);
        actingAs($s['user'])->postJson('/api/plan/2027-02/copy-from-previous')->assertOk()->assertJsonPath('data.created', 0);

        $copied = PlanItem::forMonth('2027-02-01')->orderBy('id')->get();
        expect($copied->pluck('name')->all())->toBe(['Makan', 'Asuransi', 'Cicilan']) // urutan bulan lalu: sort_order, lalu id
            ->and($copied->pluck('source')->unique()->all())->toBe([PlanItemSource::Copied])
            ->and($copied->firstWhere('name', 'Cicilan')?->due_on?->toDateString())->toBe('2027-02-28')
            ->and($copied->firstWhere('name', 'Cicilan')?->account_id)->toBe($s['bca']->id)
            ->and($copied->firstWhere('name', 'Makan')?->planned_amount)->toBe(2_000_000)
            ->and($copied->whereNotNull('skipped_at'))->toHaveCount(0); // skip tidak ikut disalin
    });

    it('only copies what the target month does not have yet', function () {
        $s = planSetup();
        PlanItem::factory()->forUser($s['user'])->forMonth('2026-10')->envelope()->create(['name' => 'Makan', 'category_id' => $s['food']->id, 'planned_amount' => 1_000_000]);
        PlanItem::factory()->forUser($s['user'])->forMonth('2026-10')->create(['name' => 'Listrik', 'category_id' => $s['bills']->id]);
        PlanItem::factory()->forUser($s['user'])->forMonth('2026-10')->create(['name' => 'Internet', 'category_id' => $s['bills']->id]);
        // Bulan tujuan sudah punya amplop Makan (nominal lain) dan checklist "listrik".
        PlanItem::factory()->forUser($s['user'])->forMonth('2026-11')->envelope()->create(['name' => 'Makan', 'category_id' => $s['food']->id, 'planned_amount' => 1_500_000]);
        PlanItem::factory()->forUser($s['user'])->forMonth('2026-11')->create(['name' => 'listrik', 'category_id' => $s['bills']->id]);

        actingAs($s['user'])->postJson('/api/plan/2026-11/copy-from-previous')->assertJsonPath('data.created', 1);

        expect(PlanItem::forMonth('2026-11-01')->orderBy('id')->pluck('name')->all())->toBe(['Makan', 'listrik', 'Internet'])
            ->and(PlanItem::forMonth('2026-11-01')->where('mode', 'envelope')->sole()->planned_amount)->toBe(1_500_000);
    });

    it('does not copy items of archived categories and drops archived default accounts', function () {
        $s = planSetup();
        $oldAccount = Account::factory()->forUser($s['user'])->archived()->create();
        $oldCategory = Category::factory()->forUser($s['user'])->archived()->create();
        PlanItem::factory()->forUser($s['user'])->forMonth('2026-10')->create(['name' => 'Rokok', 'category_id' => $oldCategory->id]);
        PlanItem::factory()->forUser($s['user'])->forMonth('2026-10')->create(['name' => 'Listrik', 'category_id' => $s['bills']->id, 'account_id' => $oldAccount->id]);

        actingAs($s['user'])->postJson('/api/plan/2026-11/copy-from-previous')->assertJsonPath('data.created', 1);

        expect(PlanItem::forMonth('2026-11-01')->sole()->only(['name', 'account_id']))->toBe(['name' => 'Listrik', 'account_id' => null]);
    });

    it('returns 0 when last month is empty', function () {
        $s = planSetup();

        actingAs($s['user'])->postJson('/api/plan/2026-11/copy-from-previous')->assertOk()->assertJsonPath('data.created', 0);
    });
});
