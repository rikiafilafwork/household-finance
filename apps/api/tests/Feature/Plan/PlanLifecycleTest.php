<?php

use App\Models\Transaction;
use Tests\Support\PlanFixture;

use function Pest\Laravel\actingAs;

/*
| #129 — skenario test plan (Architecture.md § Minggu 3) yang belum tercakup oleh
| CheckPlanItemTest, PlanComparisonTest, EnvelopeAlertTest dan ConcurrentWritesTest:
| siklus hidup item dilihat dari angka rencana, bukan hanya dari saldo.
*/

function linkedTxId(PlanFixture $f, string $item): int
{
    return (int) Transaction::ownedBy($f->user)->where('plan_item_id', $f->item[$item]->id)->value('id');
}

it('does not leak an unchecked or deleted checklist transaction into the envelope of its category', function (string $how) {
    $f = new PlanFixture;
    $f->envelope('Makan', 1_000_000);
    $f->checklist('Katering kantor', 'Makan', 300_000);
    $f->check('Katering kantor', 300_000);
    $f->spend('Makan', 200_000);
    $txId = linkedTxId($f, 'Katering kantor');

    // Setelah dibatalkan, plan_item_id transaksi jadi NULL — tapi ia soft-deleted, jadi
    // tidak boleh tiba-tiba terhitung sebagai pengeluaran manual di amplop Makan.
    match ($how) {
        'uncheck' => actingAs($f->user)->postJson("/api/plan-items/{$f->item['Katering kantor']->id}/uncheck")->assertOk(),
        'delete' => actingAs($f->user)->deleteJson("/api/transactions/{$txId}")->assertNoContent(),
        default => throw new LogicException("Cara tidak dikenal: {$how}"),
    };

    $plan = $f->comparison();
    expect($plan->item($f->item['Makan']->id)?->actual)->toBe(200_000)
        ->and($plan->item($f->item['Katering kantor']->id)?->status())->toBe('planned')
        ->and($plan->totals['expense'])->toBe(['planned' => 1_300_000, 'actual' => 200_000, 'unplanned' => 0, 'unpaid' => 300_000, 'envelope_remaining' => 800_000]);
    assertLedgerBalanced();
})->with(['uncheck', 'delete']);

it('shows the whole check → uncheck → check again cycle on GET /api/plan/{month}', function () {
    $f = new PlanFixture;
    $f->checklist('Listrik', 'Tagihan', 500_000);
    $id = $f->item['Listrik']->id;
    $plan = fn () => actingAs($f->user)->getJson('/api/plan/2026-11')->assertOk();

    $plan()->assertJsonPath('data.items.0.status', 'planned')->assertJsonPath('data.totals.expense.unpaid', 500_000);

    $f->check('Listrik', 487_000);
    $plan()->assertJsonPath('data.items.0.status', 'done')
        ->assertJsonPath('data.items.0.actual', 487_000)
        ->assertJsonPath('data.totals.expense.actual', 487_000)
        ->assertJsonPath('data.totals.expense.unpaid', 0)
        ->assertJsonPath('data.totals.remaining_budget', 13_000);

    actingAs($f->user)->deleteJson('/api/transactions/'.linkedTxId($f, 'Listrik'))->assertNoContent();
    $plan()->assertJsonPath('data.items.0.status', 'planned')
        ->assertJsonPath('data.items.0.actual', 0)
        ->assertJsonPath('data.totals.expense.actual', 0)
        ->assertJsonPath('data.totals.expense.unpaid', 500_000);

    $f->check('Listrik', 500_000);
    $plan()->assertJsonPath('data.items.0.status', 'done')->assertJsonPath('data.items.0.actual', 500_000);

    expect(Transaction::where('plan_item_id', $id)->count())->toBe(1)
        ->and($f->bca->fresh()?->balance)->toBe(20_000_000 - 500_000);
    assertLedgerBalanced();
});

it('takes a skipped checklist item out of the plan and puts it back on unskip', function () {
    $f = new PlanFixture;
    $f->checklist('Listrik', 'Tagihan', 500_000);
    $f->checklist('Asuransi tahunan', 'Asuransi', 1_000_000);
    $skip = fn (string $action) => actingAs($f->user)->postJson("/api/plan-items/{$f->item['Asuransi tahunan']->id}/{$action}")->assertOk();

    $skip('skip');
    $plan = $f->comparison();
    expect($plan->totals['expense'])->toBe(['planned' => 500_000, 'actual' => 0, 'unplanned' => 0, 'unpaid' => 500_000, 'envelope_remaining' => 0])
        ->and($plan->remainingBudget())->toBe(500_000)
        ->and($plan->item($f->item['Asuransi tahunan']->id)?->status())->toBe('skipped');

    // Bayar manual di kategori yang sama selama item di-skip → di luar rencana, bukan "centang".
    $f->spend('Asuransi', 950_000);
    expect($f->comparison()->totals['expense'])->toMatchArray(['actual' => 950_000, 'unplanned' => 950_000]);

    $skip('unskip');
    $plan = $f->comparison();
    expect($plan->totals['expense'])->toBe(['planned' => 1_500_000, 'actual' => 950_000, 'unplanned' => 950_000, 'unpaid' => 1_500_000, 'envelope_remaining' => 0])
        ->and($plan->item($f->item['Asuransi tahunan']->id)?->status())->toBe('planned');
});

it('counts a manual expense in a checklist-only category as unplanned, not as the checklist item', function () {
    $f = new PlanFixture;
    $f->checklist('Internet', 'Tagihan', 350_000);
    $f->check('Internet', 350_000);
    $f->spend('Tagihan', 25_000); // biaya admin, dicatat manual

    $plan = $f->comparison();
    expect($plan->item($f->item['Internet']->id)?->actual)->toBe(350_000)
        ->and($plan->totals['expense'])->toBe(['planned' => 350_000, 'actual' => 375_000, 'unplanned' => 25_000, 'unpaid' => 0, 'envelope_remaining' => 0])
        ->and($plan->remainingBudget())->toBe(-25_000);
});

it('keeps a checked bill out of the envelope of the month it was paid in', function () {
    $f = new PlanFixture;
    $f->checklist('Cicilan Desember', 'Cicilan', 1_200_000);
    $f->item['Cicilan Desember']->update(['month' => '2026-12-01']);
    $f->envelope('Cicilan', 2_000_000); // amplop November, kategori sama
    $f->check('Cicilan Desember', 1_200_000, '2026-11-28'); // tagihan Desember dibayar di November

    expect($f->comparison('2026-11')->item($f->item['Cicilan']->id)?->actual)->toBe(0)
        ->and($f->comparison('2026-11')->totals['expense']['actual'])->toBe(0)
        ->and($f->comparison('2026-12')->totals['expense'])->toMatchArray(['actual' => 1_200_000, 'unpaid' => 0]);
});
