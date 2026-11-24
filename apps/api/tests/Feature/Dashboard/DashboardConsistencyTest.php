<?php

use App\Enums\PlanMode;
use App\Models\Account;
use App\Models\PlanItem;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\PlanFixture;

use function Pest\Laravel\actingAs;

/*
| #133 — Beranda = Rencana untuk bulan-bulan acak.
|
| Generator ber-seed mengisi 6 bulan (Jun–Nov 2026) lewat API: checklist & amplop pemasukan/
| pengeluaran, centang (sebagian bertanggal di bulan lain), batal centang, skip, transaksi
| tak terhubung (ada yang tanpa kategori / kategori tanpa amplop / kategori amplop yang di-skip),
| hapus transaksi, transfer, sesuaikan saldo. Lalu per bulan:
|   oracle naif dari data mentah  ==  GET /api/plan/{month}  ==  GET /api/dashboard?month=
| Oracle sengaja ditulis ulang dari definisi (bukan memanggil PlanComparisonQuery) supaya dua
| endpoint yang memakai query yang sama tidak bisa "sama-sama salah".
|
| "= laporan summary" menyusul saat GET /reports/summary ada (#58, Minggu 7).
*/

const RANDOM_HISTORY_MONTHS = ['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'];

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @return array{income: array<string, int>, expense: array<string, int>, remaining_budget: int, still_to_spend: int}
 */
function oracleTotals(User $user, string $month): array
{
    $first = "{$month}-01";
    $last = CarbonImmutable::parse($first)->endOfMonth()->toDateString();
    $items = PlanItem::ownedBy($user)->where('month', $first)->get();
    $txs = Transaction::ownedBy($user)->get(); // soft delete otomatis tidak ikut

    $out = [];
    foreach (['income', 'expense'] as $type) {
        $active = $items->filter(fn (PlanItem $i) => $i->type->value === $type && $i->skipped_at === null);
        $checklists = $active->filter(fn (PlanItem $i) => $i->mode === PlanMode::Checklist);
        $envelopes = $active->filter(fn (PlanItem $i) => $i->mode === PlanMode::Envelope);
        $linked = fn (PlanItem $i) => $txs->first(fn (Transaction $t) => $t->plan_item_id === $i->id);
        $unlinked = $txs->filter(fn (Transaction $t) => $t->plan_item_id === null
            && $t->type->value === $type
            && $t->occurred_on->toDateString() >= $first
            && $t->occurred_on->toDateString() <= $last);
        $envelopeCats = $envelopes->pluck('category_id')->all();
        $abs = fn (Transaction $t) => abs($t->amount);

        $out[$type] = [
            'planned' => (int) $active->sum('planned_amount'),
            'actual' => (int) $checklists->sum(fn (PlanItem $i) => abs($linked($i)->amount ?? 0)) + (int) $unlinked->sum($abs),
            'unplanned' => (int) $unlinked->reject(fn (Transaction $t) => in_array($t->category_id, $envelopeCats, true))->sum($abs),
            'unpaid' => (int) $checklists->filter(fn (PlanItem $i) => $linked($i) === null)->sum('planned_amount'),
            'envelope_remaining' => (int) $envelopes->sum(fn (PlanItem $i) => max(0, $i->planned_amount
                - (int) $unlinked->filter(fn (Transaction $t) => $t->category_id === $i->category_id)->sum($abs))),
        ];
    }

    return [
        'income' => $out['income'],
        'expense' => $out['expense'],
        'remaining_budget' => $out['expense']['planned'] - $out['expense']['actual'],
        'still_to_spend' => $out['expense']['unpaid'] + $out['expense']['envelope_remaining'],
    ];
}

/**
 * Isi 6 bulan secara acak (deterministik per seed). Mengembalikan hitungan kasus yang tercipta
 * supaya test bisa memastikan generator benar-benar menyentuh kasus tepi.
 *
 * @return array<string, int>
 */
function seedRandomMonths(PlanFixture $f, int $seed): array
{
    mt_srand($seed);
    $pick = fn (array $xs) => $xs[mt_rand(0, count($xs) - 1)];
    $chance = fn (int $percent) => mt_rand(1, 100) <= $percent;
    $amount = fn (int $min, int $max) => mt_rand($min, $max) * 10_000;
    $expenseCats = ['Cicilan', 'Tagihan', 'Makan', 'Transport', 'Hiburan', 'Kesehatan', 'Asuransi'];
    $incomeCats = ['Gaji', 'Freelance'];
    $cash = Account::factory()->forUser($f->user)->cash()->opening(1_000_000)->create();
    $as = actingAs($f->user);
    $stats = ['checks' => 0, 'off_month_checks' => 0, 'unchecks' => 0, 'skips' => 0, 'skipped_envelope_spend' => 0, 'uncategorized' => 0, 'deleted' => 0, 'transfers' => 0];

    foreach (RANDOM_HISTORY_MONTHS as $month) {
        $days = (int) CarbonImmutable::parse("{$month}-01")->daysInMonth;
        $date = fn () => sprintf('%s-%02d', $month, mt_rand(1, $days));
        $items = [];

        // Amplop: kategori berbeda (unique per bulan), kadang satu amplop pemasukan.
        $envelopeCats = array_slice(array_values(array_filter($expenseCats, fn () => $chance(45))), 0, 3);
        foreach ($envelopeCats as $cat) {
            $items[] = PlanItem::factory()->forUser($f->user)->forMonth($month)->envelope()->create([
                'name' => $cat, 'category_id' => $f->cat[$cat]->id, 'planned_amount' => $amount(10, 150),
            ]);
        }
        if ($chance(40)) {
            $items[] = PlanItem::factory()->forUser($f->user)->forMonth($month)->envelope()->create([
                'name' => 'Freelance', 'type' => 'income', 'category_id' => $f->cat['Freelance']->id, 'planned_amount' => $amount(50, 300),
            ]);
        }
        // Checklist.
        for ($n = mt_rand(1, 6); $n > 0; $n--) {
            $income = $chance(20);
            $items[] = PlanItem::factory()->forUser($f->user)->forMonth($month)->create([
                'name' => 'Item '.Str::random(4),
                'type' => $income ? 'income' : 'expense',
                'category_id' => $f->cat[$pick($income ? $incomeCats : $expenseCats)]->id,
                'planned_amount' => $amount($income ? 100 : 5, $income ? 900 : 200),
                'account_id' => $f->bca->id,
            ]);
        }

        foreach ($items as $item) {
            if ($chance(20)) {
                $as->postJson("/api/plan-items/{$item->id}/skip")->assertOk();
                $stats['skips']++;

                continue;
            }
            if ($item->mode !== PlanMode::Checklist || ! $chance(65)) {
                continue;
            }
            $offMonth = $chance(15);
            $as->postJson("/api/plan-items/{$item->id}/check", [
                'client_id' => (string) Str::uuid(),
                // Aktual sering beda dari rencana; kadang dibayar di bulan sebelumnya.
                'amount' => $chance(50) ? $item->planned_amount : $amount(1, 250),
                'occurred_on' => $offMonth ? CarbonImmutable::parse("{$month}-01")->subDays(mt_rand(1, 5))->toDateString() : $date(),
            ])->assertCreated();
            $stats['checks']++;
            $stats['off_month_checks'] += (int) $offMonth;
            if ($chance(15)) {
                $as->postJson("/api/plan-items/{$item->id}/uncheck")->assertOk();
                $stats['unchecks']++;
            }
        }

        $skippedEnvelopeCats = collect($items)
            ->filter(fn (PlanItem $i) => $i->mode === PlanMode::Envelope && $i->type->value === 'expense' && $i->fresh()?->skipped_at !== null)
            ->map(fn (PlanItem $i) => $f->cat[$i->name]->id)->all();

        // Transaksi tak terhubung.
        for ($n = mt_rand(3, 12); $n > 0; $n--) {
            $income = $chance(15);
            $uncategorized = $chance(10);
            $cat = $uncategorized ? null : $pick($income ? $incomeCats : $expenseCats);
            $id = $f->spend($cat, $amount(1, 60), $date(), $income ? 'income' : 'expense');
            $stats['uncategorized'] += (int) $uncategorized;
            $stats['skipped_envelope_spend'] += (int) ($cat !== null && in_array($f->cat[$cat]->id, $skippedEnvelopeCats, true));
            if ($chance(15)) {
                $as->deleteJson("/api/transactions/{$id}")->assertNoContent();
                $stats['deleted']++;
            }
        }

        // Derau yang tidak boleh dihitung: transfer dan sesuaikan saldo.
        if ($chance(60)) {
            $as->postJson('/api/transfers', [
                'from_account_id' => $f->bca->id, 'to_account_id' => $cash->id, 'amount' => $amount(5, 50), 'occurred_on' => $date(), 'client_id' => (string) Str::uuid(),
            ])->assertCreated();
            $stats['transfers']++;
        }
        if ($chance(30)) {
            $as->postJson("/api/accounts/{$cash->id}/adjust", ['actual_balance' => $amount(1, 100), 'occurred_on' => $date()])->assertCreated();
        }
    }

    return $stats;
}

it('matches the hand-rolled oracle and GET /api/plan for every month of a random history', function (int $seed) {
    CarbonImmutable::setTestNow('2026-11-30 12:00:00 UTC');
    $f = new PlanFixture;
    $stats = seedRandomMonths($f, $seed);

    foreach (RANDOM_HISTORY_MONTHS as $month) {
        $oracle = oracleTotals($f->user, $month);
        /** @var array<string, mixed> $plan */
        $plan = actingAs($f->user)->getJson("/api/plan/{$month}")->assertOk()->json('data');
        /** @var array<string, mixed> $dash */
        $dash = actingAs($f->user)->getJson("/api/dashboard?month={$month}")->assertOk()->json('data');
        /** @var list<array<string, mixed>> $planItems */
        $planItems = $plan['items'];
        /** @var list<array<string, mixed>> $warnings */
        $warnings = $dash['envelopes_warning'];

        $ctx = "seed {$seed}, bulan {$month}";
        expect($plan['totals'])->toBe($oracle, "Rencana ≠ oracle ({$ctx})");
        expect([
            'income' => $dash['income'],
            'expense' => $dash['expense'],
            'remaining_budget' => $dash['remaining_budget'],
            'still_to_spend' => $dash['still_to_spend'],
        ])->toBe($oracle, "Beranda ≠ oracle ({$ctx})");

        // Chart kategori Beranda = baris pengeluaran Rencana, dan jumlahnya = total.
        $planExpenseRows = array_values(array_filter($plan['categories'], fn (array $r) => $r['type'] === 'expense'));
        expect(array_map(fn (array $r) => [$r['id'], $r['planned'], $r['actual']], $dash['categories']))
            ->toBe(array_map(fn (array $r) => [$r['category_id'], $r['planned'], $r['actual']], $planExpenseRows), $ctx)
            ->and(array_sum(array_column($dash['categories'], 'actual')))->toBe($oracle['expense']['actual'], $ctx)
            ->and(array_sum(array_column($dash['categories'], 'planned')))->toBe($oracle['expense']['planned'], $ctx);

        // Amplop hampir habis = amplop pengeluaran Rencana yang ≥ 80%.
        $hot = collect($planItems)
            ->filter(fn (array $i) => $i['mode'] === 'envelope' && $i['type'] === 'expense' && $i['status'] !== 'skipped' && $i['percent'] >= 80)
            ->sortByDesc('percent')->map(fn (array $i) => [$i['id'], $i['percent']])->values()->all();
        expect(collect($warnings)->sortByDesc('percent')->map(fn (array $i) => [$i['id'], $i['percent']])->values()->all())->toBe($hot, $ctx);

        // Sumber pemasukan = item pemasukan Rencana dengan aktual yang sama.
        expect(array_map(fn (array $r) => [$r['id'], $r['actual']], $dash['income_sources']))
            ->toBe(array_values(array_map(fn (array $i) => [$i['id'], $i['actual']], array_filter($plan['items'], fn (array $i) => $i['type'] === 'income'))), $ctx);
    }

    // Tren 6 bulan di Beranda November = total Rencana tiap bulan.
    $trend = actingAs($f->user)->getJson('/api/dashboard?month=2026-11')->assertOk()->json('data.trend');
    expect($trend)->toBe(array_map(fn (string $m) => [
        'month' => $m,
        'income_actual' => oracleTotals($f->user, $m)['income']['actual'],
        'expense_actual' => oracleTotals($f->user, $m)['expense']['actual'],
        'expense_planned' => oracleTotals($f->user, $m)['expense']['planned'],
    ], RANDOM_HISTORY_MONTHS));

    // Generator benar-benar menyentuh kasus tepi (kalau tidak, test ini kosong).
    expect(array_keys(array_filter($stats, fn (int $n) => $n === 0)))->toBe([], "seed {$seed}: kasus tidak tersentuh");
})->with([7, 42, 2026]);
