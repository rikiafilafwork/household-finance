<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\PlanTemplate;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

/*
| #47 — skenario template lewat COMMAND (bukan hanya Action), seperti yang dijalankan scheduler:
| generate & auto-post 2x berurutan, tanggal 31 melewati Februari biasa & kabisat, dan satu tahun
| penuh jadwal harian (generate 00:10, auto-post 00:20 waktu lokal) dengan template diubah di tengah
| jalan. Kasus per-aturan ada di PlanTemplateTest, PlanGenerateTest, AutoPostTest, MonthlyDueDateTest;
| paralel di tests/Concurrency.
*/

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @return array{user: User, bca: Account, bills: Category, tpl: array<string, PlanTemplate>}
 */
function scenarioSetup(): array
{
    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);
    $bca = Account::factory()->forUser($user)->opening(10_000_000)->create(['name' => 'BCA']);
    $bills = Category::factory()->forUser($user)->create(['name' => 'Tagihan']);
    $salary = Category::factory()->forUser($user)->income()->create(['name' => 'Gaji']);
    $food = Category::factory()->forUser($user)->create(['name' => 'Makan']);
    $insurance = Category::factory()->forUser($user)->create(['name' => 'Asuransi']);
    $t = fn (array $a) => PlanTemplate::factory()->forUser($user)->create($a);

    return ['user' => $user, 'bca' => $bca, 'bills' => $bills, 'tpl' => [
        'Gaji' => $t(['name' => 'Gaji', 'type' => 'income', 'category_id' => $salary->id, 'planned_amount' => 8_000_000, 'day_of_month' => 25, 'account_id' => $bca->id, 'auto_post' => true]),
        'Cicilan' => $t(['name' => 'Cicilan', 'category_id' => $bills->id, 'planned_amount' => 1_200_000, 'day_of_month' => 31, 'account_id' => $bca->id, 'auto_post' => true]),
        'Listrik' => $t(['name' => 'Listrik', 'category_id' => $bills->id, 'planned_amount' => 500_000, 'day_of_month' => 20, 'account_id' => $bca->id]),
        'Makan' => PlanTemplate::factory()->forUser($user)->envelope()->create(['name' => 'Makan', 'category_id' => $food->id, 'planned_amount' => 2_000_000]),
        'Asuransi' => PlanTemplate::factory()->forUser($user)->yearly(3)->create(['name' => 'Asuransi', 'category_id' => $insurance->id, 'planned_amount' => 1_000_000, 'day_of_month' => 15, 'account_id' => $bca->id, 'auto_post' => true]),
    ]];
}

it('plan:generate run twice in a row creates the month once', function () {
    $s = scenarioSetup();

    artisan('plan:generate', ['--month' => '2027-03'])->expectsOutputToContain('5 item dibuat untuk 1 user')->assertSuccessful();
    artisan('plan:generate', ['--month' => '2027-03'])->expectsOutputToContain('0 item dibuat untuk 1 user')->assertSuccessful();

    expect(PlanItem::ownedBy($s['user'])->forMonth('2027-03-01')->count())->toBe(5);
});

it('plan:auto-post run twice in a row posts each due item once', function () {
    $s = scenarioSetup();
    artisan('plan:generate', ['--month' => '2027-03'])->assertSuccessful();
    CarbonImmutable::setTestNow('2027-03-31 17:20:00 UTC'); // 1 Apr 00.20 WIB: semua item Maret jatuh tempo

    artisan('plan:auto-post')->expectsOutputToContain('Selesai: 3 item dicentang otomatis, 0 dilewati.')->assertSuccessful();
    artisan('plan:auto-post')->expectsOutputToContain('Selesai: 0 item dicentang otomatis, 0 dilewati.')->assertSuccessful();

    expect(Transaction::ownedBy($s['user'])->count())->toBe(3)
        ->and($s['bca']->fresh()?->balance)->toBe(10_000_000 + 8_000_000 - 1_200_000 - 1_000_000);
    assertLedgerBalanced();
});

it('keeps day 31 through a normal and a leap February: 31 / 28 / … / 29 / 31', function () {
    $s = scenarioSetup();
    foreach (['2027-01', '2027-02', '2027-03', '2028-01', '2028-02', '2028-03'] as $month) {
        artisan('plan:generate', ['--month' => $month])->assertSuccessful();
    }

    expect(PlanItem::ownedBy($s['user'])->where('name', 'Cicilan')->orderBy('month')->pluck('due_on')->map->toDateString()->all())
        ->toBe(['2027-01-31', '2027-02-28', '2027-03-31', '2028-01-31', '2028-02-29', '2028-03-31']);
});

it('runs a whole year of the real schedule: right items each month, one transaction each, ledger balanced', function () {
    $s = scenarioSetup();
    // Hari pertama simulasi: 31 Des 2026 17.00 UTC = 1 Jan 2027 00.00 WIB.
    $day = CarbonImmutable::parse('2026-12-31 17:00:00 UTC');
    $end = CarbonImmutable::parse('2027-12-31 17:00:00 UTC');

    $downtime = ['2027-05-24', '2027-05-25', '2027-05-26', '2027-05-27']; // server mati: gaji 25 Mei tersusul 28 Mei

    while ($day < $end) {
        $local = $day->setTimezone('Asia/Jakarta');
        if (in_array($local->format('Y-m-d'), $downtime, true)) {
            $day = $day->addDay();

            continue;
        }
        CarbonImmutable::setTestNow($day->addMinutes(10));        // 00.10 WIB
        artisan('plan:generate', ['--scheduled' => true])->assertSuccessful();
        if ($local->day === 1) {
            // Rencana bulan baru sudah ada sejak 00.10 tanggal 1, bukan menyusul belakangan.
            expect(PlanItem::ownedBy($s['user'])->forMonth($local->format('Y-m-01'))->count())->toBeGreaterThanOrEqual(4);
        }
        CarbonImmutable::setTestNow($day->addMinutes(20));        // 00.20 WIB
        artisan('plan:auto-post')->assertSuccessful();
        CarbonImmutable::setTestNow($day->addHours(13)->addMinutes(10)); // 13.10 WIB: bukan jam 00 → tidak generate
        artisan('plan:generate', ['--scheduled' => true])->assertSuccessful();

        if ($local->format('Y-m-d') === '2027-06-15') {
            // Pertengahan tahun: cicilan naik & pindah ke tanggal 28 (Juni sudah dibuat → tetap).
            actingAs($s['user'])->patchJson("/api/plan-templates/{$s['tpl']['Cicilan']->id}", ['planned_amount' => 1_300_000, 'day_of_month' => 28])->assertOk();
        }
        $day = $day->addDay();
    }

    $items = PlanItem::ownedBy($s['user'])->orderBy('month')->get();
    $byName = fn (string $name) => $items->where('name', $name);

    expect($items->count())->toBe(12 * 4 + 1)                               // 4 bulanan × 12 + asuransi sekali
        ->and($items->pluck('month')->map->format('Y-m')->unique()->count())->toBe(12)
        ->and($byName('Asuransi')->map(fn ($i) => $i->due_on?->toDateString())->values()->all())->toBe(['2027-03-15'])
        ->and($byName('Cicilan')->map(fn ($i) => $i->due_on?->toDateString().' '.$i->planned_amount)->values()->all())->toBe([
            '2027-01-31 1200000', '2027-02-28 1200000', '2027-03-31 1200000', '2027-04-30 1200000',
            '2027-05-31 1200000', '2027-06-30 1200000', '2027-07-28 1300000', '2027-08-28 1300000',
            '2027-09-28 1300000', '2027-10-28 1300000', '2027-11-28 1300000', '2027-12-28 1300000',
        ]);

    // Auto-post: gaji 12×, cicilan 12× (Desember 28 sudah lewat), asuransi 1×; listrik tidak pernah.
    $tx = Transaction::ownedBy($s['user'])->get();
    $maySalary = $byName('Gaji')->first(fn (PlanItem $i) => $i->month->format('Y-m') === '2027-05');
    expect($maySalary?->transaction?->occurred_on->toDateString())->toBe('2027-05-25')   // tanggal jatuh tempo, bukan hari susulan
        ->and($maySalary?->auto_posted_at?->setTimezone('Asia/Jakarta')->toDateString())->toBe('2027-05-28');
    expect($tx->count())->toBe(25)
        ->and($tx->whereNotNull('plan_item_id')->unique('plan_item_id')->count())->toBe(25)
        ->and($tx->every(fn (Transaction $t) => $t->occurred_on->toDateString() === PlanItem::ownedBy($s['user'])->find($t->plan_item_id)?->due_on?->toDateString()))->toBeTrue()
        ->and($byName('Listrik')->every(fn (PlanItem $i) => ! $i->isDone()))->toBeTrue()
        ->and($s['bca']->fresh()?->balance)->toBe(10_000_000 + 12 * 8_000_000 - 6 * 1_200_000 - 6 * 1_300_000 - 1_000_000);

    artisan('accounts:reconcile', ['--no-notify' => true])->assertSuccessful();
    assertLedgerBalanced();
});
