<?php

use App\Actions\Plan\GeneratePlanFromTemplates;
use App\Enums\PlanItemSource;
use App\Models\Account;
use App\Models\BudgetAlert;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\PlanTemplate;
use App\Models\User;
use App\Plan\PlanMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

/*
| Keputusan #9: plan:generate / "Buat dari template". Idempoten (UNIQUE (template_id, month)),
| tahunan hanya di bulannya, jatuh tempo tanpa drift, mengubah template tidak menyentuh bulan lama.
*/

/**
 * @return array{user: User, bca: Account, food: Category, bills: Category, salary: Category, insurance: Category}
 */
function generateSetup(?User $user = null): array
{
    $user ??= User::factory()->create();

    return [
        'user' => $user,
        'bca' => Account::factory()->forUser($user)->opening(5_000_000)->create(['name' => 'BCA']),
        'food' => Category::factory()->forUser($user)->create(['name' => 'Makan']),
        'bills' => Category::factory()->forUser($user)->create(['name' => 'Tagihan']),
        'salary' => Category::factory()->forUser($user)->income()->create(['name' => 'Gaji']),
        'insurance' => Category::factory()->forUser($user)->create(['name' => 'Asuransi']),
    ];
}

/**
 * Template khas: gaji 25 (auto-post), listrik 20, cicilan 31, amplop makan, asuransi tahunan Maret.
 *
 * @param  array{user: User, bca: Account, food: Category, bills: Category, salary: Category, insurance: Category}  $s
 * @return array<string, PlanTemplate>
 */
function typicalTemplates(array $s): array
{
    $t = fn (array $attrs) => PlanTemplate::factory()->forUser($s['user'])->create($attrs);

    return [
        'Gaji' => $t(['name' => 'Gaji', 'type' => 'income', 'category_id' => $s['salary']->id, 'planned_amount' => 8_000_000, 'day_of_month' => 25, 'account_id' => $s['bca']->id, 'auto_post' => true]),
        'Listrik' => $t(['name' => 'Listrik', 'category_id' => $s['bills']->id, 'planned_amount' => 500_000, 'day_of_month' => 20, 'account_id' => $s['bca']->id]),
        'Cicilan' => $t(['name' => 'Cicilan', 'category_id' => $s['bills']->id, 'planned_amount' => 1_200_000, 'day_of_month' => 31]),
        'Makan' => PlanTemplate::factory()->forUser($s['user'])->envelope()->create(['name' => 'Makan', 'category_id' => $s['food']->id, 'planned_amount' => 2_000_000]),
        'Asuransi' => PlanTemplate::factory()->forUser($s['user'])->yearly(3)->create(['name' => 'Asuransi', 'category_id' => $s['insurance']->id, 'planned_amount' => 1_000_000, 'day_of_month' => 15]),
        'Lama' => $t(['name' => 'Langganan lama', 'category_id' => $s['bills']->id, 'is_active' => false]),
    ];
}

function generate(User $user, string $month): int
{
    return app(GeneratePlanFromTemplates::class)->handle($user, PlanMonth::parse($month));
}

/**
 * @return array<string, array{due_on: string|null, planned_amount: int, mode: string, account_id: int|null}>
 */
function itemsOf(User $user, string $month): array
{
    return PlanItem::ownedBy($user)->forMonth("{$month}-01")->orderBy('id')->get()
        ->mapWithKeys(fn (PlanItem $i) => [$i->name => [
            'due_on' => $i->due_on?->toDateString(), 'planned_amount' => $i->planned_amount, 'mode' => $i->mode->value, 'account_id' => $i->account_id,
        ]])->all();
}

it('fills a month from the active templates, with per-month due dates', function () {
    $s = generateSetup();
    $tpl = typicalTemplates($s);

    expect(generate($s['user'], '2026-11'))->toBe(4);

    expect(itemsOf($s['user'], '2026-11'))->toBe([
        'Gaji' => ['due_on' => '2026-11-25', 'planned_amount' => 8_000_000, 'mode' => 'checklist', 'account_id' => $s['bca']->id],
        'Listrik' => ['due_on' => '2026-11-20', 'planned_amount' => 500_000, 'mode' => 'checklist', 'account_id' => $s['bca']->id],
        'Cicilan' => ['due_on' => '2026-11-30', 'planned_amount' => 1_200_000, 'mode' => 'checklist', 'account_id' => null],
        'Makan' => ['due_on' => null, 'planned_amount' => 2_000_000, 'mode' => 'envelope', 'account_id' => null],
    ]);
    $gaji = PlanItem::ownedBy($s['user'])->where('name', 'Gaji')->sole();
    expect($gaji->source)->toBe(PlanItemSource::Template)
        ->and($gaji->template_id)->toBe($tpl['Gaji']->id);
});

it('is idempotent: a second run creates nothing', function () {
    $s = generateSetup();
    typicalTemplates($s);

    expect(generate($s['user'], '2026-11'))->toBe(4)
        ->and(generate($s['user'], '2026-11'))->toBe(0)
        ->and(PlanItem::ownedBy($s['user'])->count())->toBe(4);
});

it('puts yearly templates only in their month', function () {
    $s = generateSetup();
    typicalTemplates($s);

    foreach (['2027-01', '2027-02', '2027-03', '2027-04'] as $month) {
        generate($s['user'], $month);
    }

    expect(PlanItem::ownedBy($s['user'])->where('name', 'Asuransi')->pluck('due_on')->map->toDateString()->all())->toBe(['2027-03-15'])
        ->and(PlanItem::ownedBy($s['user'])->where('name', 'Langganan lama')->count())->toBe(0);
});

it('keeps day 31 at the end of each month without drifting after February', function () {
    $s = generateSetup();
    typicalTemplates($s);

    foreach (['2028-01', '2028-02', '2028-03', '2028-04'] as $month) {
        generate($s['user'], $month);
    }

    expect(PlanItem::ownedBy($s['user'])->where('name', 'Cicilan')->orderBy('month')->pluck('due_on')->map->toDateString()->all())
        ->toBe(['2028-01-31', '2028-02-29', '2028-03-31', '2028-04-30']);
});

it('does not recreate an item the user skipped, nor duplicate manual or copied ones', function () {
    $s = generateSetup();
    $tpl = typicalTemplates($s);
    generate($s['user'], '2026-10');
    // November: listrik dibuat manual, amplop makan manual, cicilan hasil "salin" dari Oktober.
    PlanItem::factory()->forUser($s['user'])->forMonth('2026-11')->create(['name' => 'listrik', 'category_id' => $s['bills']->id]);
    PlanItem::factory()->forUser($s['user'])->forMonth('2026-11')->envelope()->create(['name' => 'Makan & jajan', 'category_id' => $s['food']->id, 'planned_amount' => 1_500_000]);
    PlanItem::factory()->forUser($s['user'])->forMonth('2026-11')->create(['name' => 'Cicilan', 'category_id' => $s['bills']->id, 'template_id' => $tpl['Cicilan']->id, 'source' => PlanItemSource::Copied]);

    expect(generate($s['user'], '2026-11'))->toBe(1); // hanya Gaji
    $gaji = PlanItem::ownedBy($s['user'])->forMonth('2026-11-01')->where('name', 'Gaji')->sole();
    actingAs($s['user'])->postJson("/api/plan-items/{$gaji->id}/skip")->assertOk();

    expect(generate($s['user'], '2026-11'))->toBe(0)
        ->and(PlanItem::ownedBy($s['user'])->forMonth('2026-11-01')->count())->toBe(4)
        ->and($gaji->fresh()?->skipped_at)->not->toBeNull();
});

it('skips templates with an archived category and drops an archived default account', function () {
    $s = generateSetup();
    typicalTemplates($s);
    $s['food']->update(['archived_at' => now()]);
    $s['bca']->update(['archived_at' => now()]);

    generate($s['user'], '2026-11');

    expect(array_keys(itemsOf($s['user'], '2026-11')))->toBe(['Gaji', 'Listrik', 'Cicilan'])
        ->and(itemsOf($s['user'], '2026-11')['Gaji']['account_id'])->toBeNull();
});

it('uses the template as it is now for new months and leaves generated months alone', function () {
    $s = generateSetup();
    $tpl = typicalTemplates($s);
    generate($s['user'], '2026-11');

    actingAs($s['user'])->patchJson("/api/plan-templates/{$tpl['Listrik']->id}", ['planned_amount' => 650_000, 'day_of_month' => 10])->assertOk();
    generate($s['user'], '2026-11');
    generate($s['user'], '2026-12');

    expect(itemsOf($s['user'], '2026-11')['Listrik'])->toMatchArray(['planned_amount' => 500_000, 'due_on' => '2026-11-20'])
        ->and(itemsOf($s['user'], '2026-12')['Listrik'])->toMatchArray(['planned_amount' => 650_000, 'due_on' => '2026-12-10']);
});

it('raises the envelope alert right away when spending already crossed 80%', function () {
    $s = generateSetup();
    typicalTemplates($s);
    actingAs($s['user'])->postJson('/api/transactions', [
        'account_id' => $s['bca']->id, 'type' => 'expense', 'category_id' => $s['food']->id, 'amount' => 1_700_000,
        'occurred_on' => '2026-11-03', 'client_id' => (string) Str::uuid(),
    ])->assertCreated();

    generate($s['user'], '2026-11');

    expect(BudgetAlert::query()->pluck('threshold')->all())->toBe([80]);
});

it('never touches another user’s plan', function () {
    $s = generateSetup();
    typicalTemplates($s);
    $other = generateSetup();

    generate($other['user'], '2026-11');

    expect(PlanItem::ownedBy($s['user'])->count())->toBe(0)
        ->and(PlanItem::ownedBy($other['user'])->count())->toBe(0);
});

describe('POST /api/plan/{month}/generate', function () {
    it('answers 201 with the count, then 200 with 0', function () {
        $s = generateSetup();
        typicalTemplates($s);

        actingAs($s['user'])->postJson('/api/plan/2026-11/generate')->assertCreated()->assertJsonPath('data.created', 4);
        actingAs($s['user'])->postJson('/api/plan/2026-11/generate')->assertOk()->assertJsonPath('data.created', 0);
        actingAs($s['user'])->getJson('/api/plan/2026-11')->assertJsonCount(4, 'data.items')->assertJsonPath('data.totals.expense.planned', 3_700_000);
    });

    it('rejects an invalid month', function () {
        $s = generateSetup();
        actingAs($s['user'])->postJson('/api/plan/2026-13/generate')->assertNotFound();
    });
});

describe('plan:generate', function () {
    afterEach(fn () => CarbonImmutable::setTestNow());

    it('fills the given month for every user', function () {
        $a = generateSetup();
        typicalTemplates($a);
        $b = generateSetup();
        typicalTemplates($b);

        artisan('plan:generate', ['--month' => '2027-03'])->assertSuccessful()->expectsOutputToContain('10 item dibuat untuk 2 user');

        expect(PlanItem::ownedBy($a['user'])->forMonth('2027-03-01')->count())->toBe(5)
            ->and(PlanItem::ownedBy($b['user'])->forMonth('2027-03-01')->count())->toBe(5);
    });

    it('uses the current month in each user’s own timezone', function () {
        // 30 Nov 23.30 UTC = 1 Des 06.30 WIB, masih 30 Nov 13.30 di Honolulu.
        CarbonImmutable::setTestNow('2026-11-30 23:30:00 UTC');
        $jakarta = generateSetup(User::factory()->create(['timezone' => 'Asia/Jakarta']));
        typicalTemplates($jakarta);
        $honolulu = generateSetup(User::factory()->create(['timezone' => 'Pacific/Honolulu']));
        typicalTemplates($honolulu);

        artisan('plan:generate')->assertSuccessful();

        expect(PlanItem::ownedBy($jakarta['user'])->pluck('month')->map->format('Y-m')->unique()->all())->toBe(['2026-12'])
            ->and(PlanItem::ownedBy($honolulu['user'])->pluck('month')->map->format('Y-m')->unique()->all())->toBe(['2026-11']);
    });

    it('with --scheduled, only runs for users at 00.xx on the 1st in their timezone', function () {
        // 31 Okt 17.10 UTC = 1 Nov 00.10 WIB; Makassar sudah 01.10; Honolulu masih 31 Okt.
        CarbonImmutable::setTestNow('2026-10-31 17:10:00 UTC');
        $users = [];
        foreach (['Asia/Jakarta', 'Asia/Makassar', 'Pacific/Honolulu'] as $tz) {
            $users[$tz] = generateSetup(User::factory()->create(['timezone' => $tz]));
            typicalTemplates($users[$tz]);
        }

        artisan('plan:generate', ['--scheduled' => true])->assertSuccessful()->expectsOutputToContain('4 item dibuat untuk 1 user');

        expect(PlanItem::ownedBy($users['Asia/Jakarta']['user'])->forMonth('2026-11-01')->count())->toBe(4)
            ->and(PlanItem::ownedBy($users['Asia/Makassar']['user'])->count())->toBe(0)
            ->and(PlanItem::ownedBy($users['Pacific/Honolulu']['user'])->count())->toBe(0);
    });

    it('does not bring back an item the user deleted when the hourly schedule runs again', function () {
        CarbonImmutable::setTestNow('2026-10-31 17:10:00 UTC'); // 1 Nov 00.10 WIB
        $s = generateSetup(User::factory()->create(['timezone' => 'Asia/Jakarta']));
        typicalTemplates($s);
        artisan('plan:generate', ['--scheduled' => true])->assertSuccessful();
        actingAs($s['user'])->deleteJson('/api/plan-items/'.PlanItem::ownedBy($s['user'])->where('name', 'Listrik')->value('id'))->assertNoContent();

        CarbonImmutable::setTestNow('2026-10-31 18:10:00 UTC'); // 01.10 WIB
        artisan('plan:generate', ['--scheduled' => true])->assertSuccessful();

        expect(PlanItem::ownedBy($s['user'])->where('name', 'Listrik')->count())->toBe(0);
    });

    it('rejects a malformed --month', function () {
        artisan('plan:generate', ['--month' => '2026-1'])->assertFailed();
    });
});

it('copies template items from last month using the template day, so day 31 does not drift', function () {
    $s = generateSetup();
    typicalTemplates($s);
    generate($s['user'], '2026-01');

    actingAs($s['user'])->postJson('/api/plan/2026-02/copy-from-previous')->assertCreated();
    actingAs($s['user'])->postJson('/api/plan/2026-03/copy-from-previous')->assertCreated();

    expect(PlanItem::ownedBy($s['user'])->where('name', 'Cicilan')->orderBy('month')->pluck('due_on')->map->toDateString()->all())
        ->toBe(['2026-01-31', '2026-02-28', '2026-03-31']);
});

it('still copies a manual item with last month’s day', function () {
    $s = generateSetup();
    PlanItem::factory()->forUser($s['user'])->forMonth('2026-02')->create(['name' => 'Arisan', 'category_id' => $s['bills']->id, 'due_on' => '2026-02-28']);

    actingAs($s['user'])->postJson('/api/plan/2026-03/copy-from-previous')->assertCreated();

    expect(PlanItem::ownedBy($s['user'])->forMonth('2026-03-01')->sole()->due_on?->toDateString())->toBe('2026-03-28');
});
