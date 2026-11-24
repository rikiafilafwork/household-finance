<?php

use App\Actions\Plan\AutoPostDueItems;
use App\Actions\Plan\GeneratePlanFromTemplates;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\PlanTemplate;
use App\Models\Transaction;
use App\Models\User;
use App\Plan\PlanMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

/*
| Keputusan #9: plan:auto-post mencentang item auto_post yang jatuh tempo lewat CheckPlanItem yang
| sama. Dijalankan 2x → 1 transaksi; yang dicentang manual / di-skip / dibatalkan tidak disentuh.
*/

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * Template gaji 25 (pemasukan) + internet 10 (autodebet) + listrik 20 (TANPA auto-post), lalu
 * rencana November dibuat dari template.
 *
 * @return array{user: User, bca: Account, salary: PlanItem, internet: PlanItem, power: PlanItem, tpl: array<string, PlanTemplate>}
 */
function autoPostSetup(string $timezone = 'Asia/Jakarta', string $month = '2026-11'): array
{
    $user = User::factory()->create(['timezone' => $timezone]);
    $bca = Account::factory()->forUser($user)->opening(1_000_000)->create(['name' => 'BCA']);
    $bills = Category::factory()->forUser($user)->create(['name' => 'Tagihan']);
    $salaryCat = Category::factory()->forUser($user)->income()->create(['name' => 'Gaji']);
    $t = fn (array $a) => PlanTemplate::factory()->forUser($user)->create($a + ['account_id' => $bca->id]);
    $tpl = [
        'Gaji' => $t(['name' => 'Gaji', 'type' => 'income', 'category_id' => $salaryCat->id, 'planned_amount' => 8_000_000, 'day_of_month' => 25, 'auto_post' => true]),
        'Internet' => $t(['name' => 'Internet', 'category_id' => $bills->id, 'planned_amount' => 350_000, 'day_of_month' => 10, 'auto_post' => true]),
        'Listrik' => $t(['name' => 'Listrik', 'category_id' => $bills->id, 'planned_amount' => 500_000, 'day_of_month' => 20]),
    ];
    app(GeneratePlanFromTemplates::class)->handle($user, PlanMonth::parse($month));
    $item = fn (string $name) => PlanItem::ownedBy($user)->forMonth("{$month}-01")->where('name', $name)->sole();

    return ['user' => $user, 'bca' => $bca, 'salary' => $item('Gaji'), 'internet' => $item('Internet'), 'power' => $item('Listrik'), 'tpl' => $tpl];
}

/**
 * @return array{posted: list<int>, skipped: array<int, string>}
 */
function autoPost(User $user, string $utc): array
{
    return app(AutoPostDueItems::class)->handle($user, CarbonImmutable::parse($utc, 'UTC'));
}

it('checks a due auto-post item like a manual check: transaction on the due date, balance moved', function () {
    $s = autoPostSetup();

    $result = autoPost($s['user'], '2026-11-12 03:00'); // 12 Nov 10.00 WIB

    expect($result)->toBe(['posted' => [$s['internet']->id], 'skipped' => []]);
    $tx = Transaction::ownedBy($s['user'])->sole();
    expect($tx->plan_item_id)->toBe($s['internet']->id)
        ->and($tx->amount)->toBe(-350_000)
        ->and($tx->occurred_on->toDateString())->toBe('2026-11-10')
        ->and($tx->client_id)->toBeNull()
        ->and($tx->note)->toBe('Internet')
        ->and($s['bca']->fresh()?->balance)->toBe(650_000)
        ->and($s['internet']->fresh()?->auto_posted_at)->not->toBeNull();
    actingAs($s['user'])->getJson('/api/plan/2026-11')->assertOk()
        ->assertJsonPath('data.items.1.name', 'Internet')
        ->assertJsonPath('data.items.1.status', 'done');
    assertLedgerBalanced();
});

it('posts income with a positive amount and leaves items without auto_post alone', function () {
    $s = autoPostSetup();

    autoPost($s['user'], '2026-11-26 03:00');

    expect(Transaction::ownedBy($s['user'])->orderBy('occurred_on')->pluck('amount', 'plan_item_id')->all())
        ->toBe([$s['internet']->id => -350_000, $s['salary']->id => 8_000_000])
        ->and($s['power']->fresh()?->isDone())->toBeFalse();
});

it('waits until the due date in the user’s own timezone', function () {
    $jakarta = autoPostSetup('Asia/Jakarta');
    $honolulu = autoPostSetup('Pacific/Honolulu');
    $now = '2026-11-09 18:00'; // 10 Nov 01.00 WIB, masih 9 Nov 08.00 di Honolulu

    expect(autoPost($jakarta['user'], $now)['posted'])->toBe([$jakarta['internet']->id])
        ->and(autoPost($honolulu['user'], $now)['posted'])->toBe([]);
});

it('creates one transaction when run twice', function () {
    $s = autoPostSetup();

    autoPost($s['user'], '2026-11-12 03:00');
    expect(autoPost($s['user'], '2026-11-12 04:00'))->toBe(['posted' => [], 'skipped' => []])
        ->and(Transaction::ownedBy($s['user'])->count())->toBe(1)
        ->and($s['bca']->fresh()?->balance)->toBe(650_000);
});

it('leaves an item checked by hand untouched', function () {
    $s = autoPostSetup();
    actingAs($s['user'])->postJson("/api/plan-items/{$s['internet']->id}/check", [
        'client_id' => (string) Str::uuid(), 'amount' => 355_000, 'occurred_on' => '2026-11-09',
    ])->assertCreated();

    autoPost($s['user'], '2026-11-12 03:00');

    expect(Transaction::ownedBy($s['user'])->sole()->amount)->toBe(-355_000)
        ->and($s['internet']->fresh()?->auto_posted_at)->toBeNull();
});

it('does not post a skipped item', function () {
    $s = autoPostSetup();
    actingAs($s['user'])->postJson("/api/plan-items/{$s['internet']->id}/skip")->assertOk();

    expect(autoPost($s['user'], '2026-11-12 03:00')['posted'])->toBe([])
        ->and(Transaction::ownedBy($s['user'])->count())->toBe(0);
});

it('does not post again after the user unchecks an auto-posted item (e.g. the debit failed)', function () {
    $s = autoPostSetup();
    autoPost($s['user'], '2026-11-12 03:00');
    actingAs($s['user'])->postJson("/api/plan-items/{$s['internet']->id}/uncheck")->assertOk();

    autoPost($s['user'], '2026-11-12 04:00');
    autoPost($s['user'], '2026-11-13 04:00');

    expect(Transaction::ownedBy($s['user'])->count())->toBe(0)
        ->and($s['bca']->fresh()?->balance)->toBe(1_000_000)
        ->and($s['internet']->fresh()?->isDone())->toBeFalse();
    // User tetap bisa mencentang manual.
    actingAs($s['user'])->postJson("/api/plan-items/{$s['internet']->id}/check", ['client_id' => (string) Str::uuid()])->assertCreated();
    assertLedgerBalanced();
});

it('stops posting when auto_post is turned off on the template', function () {
    $s = autoPostSetup();
    actingAs($s['user'])->patchJson("/api/plan-templates/{$s['tpl']['Internet']->id}", ['auto_post' => false])->assertOk();

    expect(autoPost($s['user'], '2026-11-12 03:00')['posted'])->toBe([]);
});

it('ignores items that were not created from an auto-post template', function () {
    $s = autoPostSetup();
    PlanItem::factory()->forUser($s['user'])->forMonth('2026-11')->create([
        'name' => 'Manual', 'category_id' => $s['power']->category_id, 'account_id' => $s['bca']->id, 'due_on' => '2026-11-01',
    ]);

    expect(autoPost($s['user'], '2026-11-12 03:00')['posted'])->toBe([$s['internet']->id]);
});

it('catches up items from last month but not older ones', function () {
    $s = autoPostSetup(month: '2026-09');
    foreach (['2026-10', '2026-11'] as $m) {
        app(GeneratePlanFromTemplates::class)->handle($s['user'], PlanMonth::parse($m));
    }

    $result = autoPost($s['user'], '2026-11-02 03:00'); // server baru hidup lagi 2 Nov

    $posted = PlanItem::ownedBy($s['user'])->whereKey($result['posted'])->orderBy('due_on')->get()
        ->map(fn (PlanItem $i) => $i->name.' '.$i->due_on?->toDateString())->all();
    expect($posted)->toBe(['Internet 2026-10-10', 'Gaji 2026-10-25'])
        ->and(PlanItem::ownedBy($s['user'])->forMonth('2026-09-01')->whereNotNull('auto_posted_at')->count())->toBe(0);
});

it('reports an item it cannot post because its default account is archived', function () {
    $s = autoPostSetup();
    $s['bca']->update(['archived_at' => now()]);

    $result = autoPost($s['user'], '2026-11-12 03:00');

    expect($result['posted'])->toBe([])
        ->and($result['skipped'])->toBe([$s['internet']->id => 'Akun ini sudah diarsip.'])
        ->and($s['internet']->fresh()?->auto_posted_at)->toBeNull(); // dicoba lagi setelah akun dipulihkan
});

it('runs for every user from the command and prints a summary', function () {
    CarbonImmutable::setTestNow('2026-11-26 03:00:00 UTC');
    $a = autoPostSetup();
    $b = autoPostSetup();
    $b['bca']->update(['archived_at' => now()]);

    artisan('plan:auto-post')
        ->expectsOutputToContain("User {$b['user']->id}, item {$b['internet']->id} dilewati: Akun ini sudah diarsip.")
        ->expectsOutputToContain('Selesai: 2 item dicentang otomatis, 2 dilewati.')
        ->assertSuccessful();

    expect(Transaction::ownedBy($a['user'])->count())->toBe(2);
    assertLedgerBalanced();
});
