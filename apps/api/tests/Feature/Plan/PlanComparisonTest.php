<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Queries\PlanComparisonQuery;
use Illuminate\Support\Str;
use Tests\Support\PlanFixture;

use function Pest\Laravel\actingAs;

/*
| Keputusan #7 & #11: PlanComparisonQuery = satu sumber angka. Fixture dihitung manual:
|   checklist pengeluaran aktual 1.715.000 (cicilan 1.200.000 + internet 350.000 + pulsa 165.000)
| + amplop aktual 2.180.000 (makan 1.350.000 + transport 510.000 + hiburan 320.000)
| + di luar rencana 180.000 (kesehatan)
| = 4.075.000. Rencana pengeluaran 5.100.000 → sisa 1.025.000, belum dibayar 500.000 (listrik).
| Pemasukan: rencana 9.500.000 (gaji 8.000.000 + freelance 1.500.000), aktual 8.000.000.
*/

function architectureFixture(): PlanFixture
{
    $f = new PlanFixture;
    $f->checklist('Cicilan motor', 'Cicilan', 1_200_000);
    $f->checklist('Internet', 'Tagihan', 350_000);
    $f->checklist('Pulsa', 'Tagihan', 150_000);
    $f->checklist('Listrik', 'Tagihan', 500_000);
    $f->envelope('Makan', 2_000_000);
    $f->envelope('Transport', 600_000);
    $f->envelope('Hiburan', 300_000);
    $f->checklist('Gaji', 'Gaji', 8_000_000, 'income');
    $f->envelope('Freelance', 1_500_000, 'income');
    $f->checklist('Asuransi tahunan', 'Asuransi', 1_000_000);
    actingAs($f->user)->postJson("/api/plan-items/{$f->item['Asuransi tahunan']->id}/skip")->assertOk();

    $f->check('Cicilan motor', 1_200_000, '2026-11-05');
    $f->check('Internet', 350_000);
    $f->check('Pulsa', 165_000); // aktual beda dari rencana
    $f->check('Gaji', 8_000_000, '2026-11-25');
    foreach ([450_000, 450_000, 450_000] as $amount) {
        $f->spend('Makan', $amount);
    }
    $f->spend('Transport', 510_000);
    $f->spend('Hiburan', 320_000);
    $f->spend('Kesehatan', 180_000); // di luar rencana

    // Derau yang TIDAK boleh ikut dihitung:
    $f->spend('Makan', 99_000, '2026-10-31');                                    // bulan lain
    actingAs($f->user)->deleteJson('/api/transactions/'.$f->spend('Makan', 77_000))->assertNoContent(); // dihapus
    $cash = Account::factory()->forUser($f->user)->cash()->create();
    actingAs($f->user)->postJson('/api/transfers', [
        'from_account_id' => $f->bca->id, 'to_account_id' => $cash->id, 'amount' => 300_000, 'occurred_on' => '2026-11-12', 'client_id' => (string) Str::uuid(),
    ])->assertCreated();                                                          // transfer
    actingAs($f->user)->postJson("/api/accounts/{$cash->id}/adjust", ['actual_balance' => 250_000, 'occurred_on' => '2026-11-20'])->assertCreated(); // adjustment
    $other = Account::factory()->create();                                       // user lain
    Transaction::factory()->forAccount($other)->expense(5_000_000)->create(['occurred_on' => '2026-11-15']);

    return $f;
}

it('matches the hand-computed fixture from Architecture.md #11', function () {
    $plan = architectureFixture()->comparison();

    expect($plan->totals['expense'])->toBe(['planned' => 5_100_000, 'actual' => 4_075_000, 'unplanned' => 180_000, 'unpaid' => 500_000, 'envelope_remaining' => 740_000])
        ->and($plan->remainingBudget())->toBe(1_025_000)
        // listrik 500.000 + ruang amplop makan 650.000 + transport 90.000 (hiburan jebol = 0)
        ->and($plan->stillToSpend())->toBe(1_240_000)
        ->and($plan->totals['income'])->toBe(['planned' => 9_500_000, 'actual' => 8_000_000, 'unplanned' => 0, 'unpaid' => 0, 'envelope_remaining' => 1_500_000]);
});

it('reports per-item actuals, statuses and envelope percentages', function () {
    $f = architectureFixture();
    $plan = $f->comparison();
    $row = fn (string $name) => $plan->item($f->item[$name]->id);

    expect([$row('Pulsa')?->actual, $row('Pulsa')?->status()])->toBe([165_000, 'done'])
        ->and([$row('Listrik')?->actual, $row('Listrik')?->status()])->toBe([0, 'planned'])
        ->and([$row('Asuransi tahunan')?->actual, $row('Asuransi tahunan')?->status()])->toBe([0, 'skipped'])
        ->and([$row('Makan')?->actual, $row('Makan')?->percent()])->toBe([1_350_000, 67])
        ->and([$row('Transport')?->actual, $row('Transport')?->percent()])->toBe([510_000, 85])
        ->and([$row('Hiburan')?->actual, $row('Hiburan')?->percent()])->toBe([320_000, 106])
        ->and($row('Freelance')?->actual)->toBe(0);
});

it('breaks actuals down by category so that the rows add up to the totals', function () {
    $plan = architectureFixture()->comparison();
    $expense = collect($plan->categories)->where('type', 'expense')->keyBy('name');

    expect($expense->map(fn ($r) => [$r['planned'], $r['actual']])->all())->toBe([
        'Makan' => [2_000_000, 1_350_000],
        'Cicilan' => [1_200_000, 1_200_000],
        'Tagihan' => [1_000_000, 515_000],
        'Transport' => [600_000, 510_000],
        'Hiburan' => [300_000, 320_000],
        'Kesehatan' => [0, 180_000],
    ])
        ->and($expense->sum('actual'))->toBe($plan->totals['expense']['actual'])
        ->and($expense->sum('planned'))->toBe($plan->totals['expense']['planned'])
        ->and($expense->has('Asuransi'))->toBeFalse(); // di-skip, tanpa transaksi
});

it('serves the same numbers from GET /api/plan/{month}', function () {
    $f = architectureFixture();

    actingAs($f->user)->getJson('/api/plan/2026-11')
        ->assertOk()
        ->assertJsonPath('data.month', '2026-11')
        ->assertJsonPath('data.totals.expense', ['planned' => 5_100_000, 'actual' => 4_075_000, 'unplanned' => 180_000, 'unpaid' => 500_000, 'envelope_remaining' => 740_000])
        ->assertJsonPath('data.totals.still_to_spend', 1_240_000)
        ->assertJsonPath('data.totals.income.actual', 8_000_000)
        ->assertJsonPath('data.totals.remaining_budget', 1_025_000)
        ->assertJsonCount(10, 'data.items')
        ->assertJsonPath('data.items.0.name', 'Cicilan motor')
        ->assertJsonPath('data.items.0.actual', 1_200_000)
        ->assertJsonPath('data.items.0.status', 'done')
        ->assertJsonPath('data.items.0.category.name', 'Cicilan');
});

it('never counts a checked transaction again in the envelope of the same category', function () {
    $f = new PlanFixture;
    $f->envelope('Makan', 1_000_000);
    $f->checklist('Katering kantor', 'Makan', 300_000);
    $f->check('Katering kantor', 300_000);
    $f->spend('Makan', 200_000);

    $plan = $f->comparison();

    expect($plan->item($f->item['Makan']->id)?->actual)->toBe(200_000)
        ->and($plan->item($f->item['Katering kantor']->id)?->actual)->toBe(300_000)
        ->and($plan->totals['expense']['actual'])->toBe(500_000)
        ->and($plan->totals['expense']['unplanned'])->toBe(0)
        ->and(collect($plan->categories)->firstWhere('name', 'Makan'))->toMatchArray(['planned' => 1_300_000, 'actual' => 500_000]);
});

it('counts the transactions of a skipped envelope as unplanned', function () {
    $f = new PlanFixture;
    $f->envelope('Hiburan', 300_000);
    actingAs($f->user)->postJson("/api/plan-items/{$f->item['Hiburan']->id}/skip")->assertOk();
    $f->spend('Hiburan', 120_000);

    $plan = $f->comparison();

    expect($plan->totals['expense'])->toBe(['planned' => 0, 'actual' => 120_000, 'unplanned' => 120_000, 'unpaid' => 0, 'envelope_remaining' => 0])
        ->and($plan->item($f->item['Hiburan']->id)?->actual)->toBe(0);
});

it('attributes a checked bill to its plan month even when paid in another month', function () {
    $f = new PlanFixture;
    $f->checklist('Cicilan motor', 'Cicilan', 1_200_000);
    $f->check('Cicilan motor', 1_200_000, '2026-10-30'); // tagihan November dibayar akhir Oktober

    expect($f->comparison('2026-11')->totals['expense']['actual'])->toBe(1_200_000)
        ->and($f->comparison('2026-10')->totals['expense'])->toBe(['planned' => 0, 'actual' => 0, 'unplanned' => 0, 'unpaid' => 0, 'envelope_remaining' => 0]);
});

it('treats uncategorized income and expense as unplanned', function () {
    $f = new PlanFixture;
    $f->spend(null, 40_000);
    $f->spend(null, 250_000, type: 'income');

    $plan = $f->comparison();

    expect($plan->totals['expense']['unplanned'])->toBe(40_000)
        ->and($plan->totals['income']['unplanned'])->toBe(250_000)
        ->and(collect($plan->categories)->pluck('name')->all())->toBe(['Tanpa kategori', 'Tanpa kategori']);
});

it('follows edits and unchecks of linked transactions immediately', function () {
    $f = new PlanFixture;
    $f->checklist('Listrik', 'Tagihan', 500_000);
    $f->check('Listrik', 500_000);
    $txId = Transaction::ownedBy($f->user)->where('plan_item_id', $f->item['Listrik']->id)->value('id');

    actingAs($f->user)->patchJson("/api/transactions/{$txId}", ['amount' => 525_000])->assertOk();
    expect($f->comparison()->totals['expense'])->toMatchArray(['actual' => 525_000, 'unpaid' => 0]);

    actingAs($f->user)->postJson("/api/plan-items/{$f->item['Listrik']->id}/uncheck")->assertOk();
    expect($f->comparison()->totals['expense'])->toMatchArray(['actual' => 0, 'unpaid' => 500_000]);
});

it('computes percentages with integer floor division (79 stays 79)', function () {
    $f = new PlanFixture;
    $f->envelope('Transport', 1_000_000);
    $f->spend('Transport', 799_999);

    expect($f->comparison()->item($f->item['Transport']->id)?->percent())->toBe(79);
});

it('returns zeros for an empty month', function () {
    $plan = (new PlanFixture)->comparison();

    expect($plan->items)->toHaveCount(0)
        ->and($plan->remainingBudget())->toBe(0)
        ->and($plan->categories)->toBe([]);
});

it('does not let an overspent envelope eat into the room of the others', function () {
    $f = new PlanFixture;
    $f->envelope('Makan', 1_000_000);
    $f->envelope('Hiburan', 200_000);
    $f->spend('Makan', 400_000);
    $f->spend('Hiburan', 500_000); // jebol 300.000

    $plan = $f->comparison();

    expect($plan->totals['expense']['envelope_remaining'])->toBe(600_000)
        ->and($plan->stillToSpend())->toBe(600_000)
        ->and($plan->remainingBudget())->toBe(300_000); // sisa anggaran tetap netto
});

it('leaves skipped envelopes out of the remaining room', function () {
    $f = new PlanFixture;
    $f->envelope('Makan', 1_000_000);
    $f->envelope('Transport', 3_000_000);
    actingAs($f->user)->postJson("/api/plan-items/{$f->item['Transport']->id}/skip")->assertOk();

    expect($f->comparison()->stillToSpend())->toBe(1_000_000);
});
