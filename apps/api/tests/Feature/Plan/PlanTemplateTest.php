<?php

use App\Enums\PlanItemSource;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\PlanTemplate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/*
| Keputusan #9: template bulanan. CRUD + aktif/nonaktif; validasi antar-field (yearly ↔ month_of_year,
| amplop tanpa tanggal, auto_post hanya checklist + tanggal + akun) dijaga di API dan CHECK di DB.
| Mengubah template tidak mengubah item bulan yang sudah ada.
*/

/**
 * @return array{user: User, bca: Account, food: Category, bills: Category, salary: Category}
 */
function templateSetup(): array
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
 * @param  array{user: User, bca: Account, food: Category, bills: Category, salary: Category}  $s
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function templateBody(array $s, array $overrides = []): array
{
    return array_merge([
        'type' => 'expense', 'mode' => 'checklist', 'name' => 'Listrik', 'category_id' => $s['bills']->id,
        'planned_amount' => 500_000, 'account_id' => $s['bca']->id, 'day_of_month' => 20,
    ], $overrides);
}

it('creates a monthly checklist template with auto-post', function () {
    $s = templateSetup();

    actingAs($s['user'])->postJson('/api/plan-templates', templateBody($s, ['name' => ' Gaji ', 'type' => 'income', 'category_id' => $s['salary']->id, 'planned_amount' => 8_000_000, 'day_of_month' => 25, 'auto_post' => true]))
        ->assertCreated()
        ->assertJsonPath('data.name', 'Gaji')
        ->assertJsonPath('data.type', 'income')
        ->assertJsonPath('data.frequency', 'monthly')
        ->assertJsonPath('data.day_of_month', 25)
        ->assertJsonPath('data.month_of_year', null)
        ->assertJsonPath('data.auto_post', true)
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.category.name', 'Gaji');

    expect(PlanTemplate::ownedBy($s['user'])->sole()->user_id)->toBe($s['user']->id);
});

it('creates a yearly template and a monthly envelope', function () {
    $s = templateSetup();

    actingAs($s['user'])->postJson('/api/plan-templates', templateBody($s, ['name' => 'Asuransi', 'frequency' => 'yearly', 'month_of_year' => 3, 'day_of_month' => 15]))
        ->assertCreated()->assertJsonPath('data.frequency', 'yearly')->assertJsonPath('data.month_of_year', 3);
    actingAs($s['user'])->postJson('/api/plan-templates', templateBody($s, ['name' => 'Makan', 'mode' => 'envelope', 'category_id' => $s['food']->id, 'day_of_month' => null, 'account_id' => null]))
        ->assertCreated()->assertJsonPath('data.mode', 'envelope')->assertJsonPath('data.day_of_month', null);
});

it('rejects inconsistent combinations', function (array $overrides, string $field) {
    $s = templateSetup();

    actingAs($s['user'])->postJson('/api/plan-templates', templateBody($s, $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(PlanTemplate::ownedBy($s['user'])->count())->toBe(0);
})->with([
    'yearly without a month' => [['frequency' => 'yearly'], 'month_of_year'],
    'monthly with a month' => [['month_of_year' => 4], 'month_of_year'],
    'weekly is not a budget frequency' => [['frequency' => 'weekly'], 'frequency'],
    'envelope with a due day' => [['mode' => 'envelope', 'day_of_month' => 5], 'day_of_month'],
    'auto-post on an envelope' => [['mode' => 'envelope', 'day_of_month' => null, 'auto_post' => true], 'auto_post'],
    'auto-post without a due day' => [['day_of_month' => null, 'auto_post' => true], 'day_of_month'],
    'auto-post without an account' => [['account_id' => null, 'auto_post' => true], 'account_id'],
    'day 32' => [['day_of_month' => 32], 'day_of_month'],
    'day 0' => [['day_of_month' => 0], 'day_of_month'],
    'month 13' => [['frequency' => 'yearly', 'month_of_year' => 13], 'month_of_year'],
    'amount as string' => [['planned_amount' => '500000'], 'planned_amount'],
    'zero amount' => [['planned_amount' => 0], 'planned_amount'],
    'auto_post as string' => [['auto_post' => 'yes'], 'auto_post'],
]);

it('checks the category and default account like plan items do', function (Closure $mutate, string $field) {
    $s = templateSetup();

    actingAs($s['user'])->postJson('/api/plan-templates', templateBody($s, $mutate($s)))
        ->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'income category on an expense' => [fn (array $s) => ['category_id' => $s['salary']->id], 'category_id'],
    'archived category' => [fn (array $s) => ['category_id' => Category::factory()->forUser($s['user'])->archived()->create()->id], 'category_id'],
    'archived account' => [fn (array $s) => ['account_id' => Account::factory()->forUser($s['user'])->archived()->create()->id], 'account_id'],
    'unknown category' => [fn (array $s) => ['category_id' => 999_999_999], 'category_id'],
]);

it('lists active templates first, income before expense, checklist by due day', function () {
    $s = templateSetup();
    $make = fn (array $attrs) => PlanTemplate::factory()->forUser($s['user'])->create($attrs + ['category_id' => $s['bills']->id]);
    $make(['name' => 'Internet', 'day_of_month' => 10]);
    $make(['name' => 'Cicilan', 'day_of_month' => 5]);
    $make(['name' => 'Lama', 'is_active' => false]);
    $make(['name' => 'Makan', 'mode' => 'envelope', 'day_of_month' => null, 'category_id' => $s['food']->id]);
    $make(['name' => 'Gaji', 'type' => 'income', 'category_id' => $s['salary']->id, 'day_of_month' => 25]);

    $names = fn (string $query = '') => actingAs($s['user'])->getJson('/api/plan-templates'.$query)->assertOk()->json('data.*.name');

    expect($names())->toBe(['Gaji', 'Cicilan', 'Internet', 'Makan', 'Lama'])
        ->and($names('?active=1'))->toBe(['Gaji', 'Cicilan', 'Internet', 'Makan'])
        ->and($names('?active=0'))->toBe(['Lama']);
});

it('deactivates and reactivates a template with PATCH', function () {
    $s = templateSetup();
    $template = PlanTemplate::factory()->forUser($s['user'])->create(['category_id' => $s['bills']->id]);

    actingAs($s['user'])->patchJson("/api/plan-templates/{$template->id}", ['is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.day_of_month', 5);
    actingAs($s['user'])->patchJson("/api/plan-templates/{$template->id}", ['is_active' => true])
        ->assertOk()->assertJsonPath('data.is_active', true);
});

it('drops fields that no longer apply when switching to envelope or to monthly', function () {
    $s = templateSetup();
    $template = PlanTemplate::factory()->forUser($s['user'])->yearly(6)->create([
        'category_id' => $s['food']->id, 'account_id' => $s['bca']->id, 'day_of_month' => 10, 'auto_post' => true,
    ]);

    actingAs($s['user'])->patchJson("/api/plan-templates/{$template->id}", ['mode' => 'envelope'])
        ->assertOk()->assertJsonPath('data.day_of_month', null)->assertJsonPath('data.auto_post', false)->assertJsonPath('data.month_of_year', 6);
    actingAs($s['user'])->patchJson("/api/plan-templates/{$template->id}", ['frequency' => 'monthly'])
        ->assertOk()->assertJsonPath('data.frequency', 'monthly')->assertJsonPath('data.month_of_year', null);
});

it('still rejects a PATCH that leaves the template inconsistent', function () {
    $s = templateSetup();
    $template = PlanTemplate::factory()->forUser($s['user'])->create(['category_id' => $s['bills']->id, 'account_id' => null]);

    actingAs($s['user'])->patchJson("/api/plan-templates/{$template->id}", ['auto_post' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['account_id']);
    actingAs($s['user'])->patchJson("/api/plan-templates/{$template->id}", ['frequency' => 'yearly'])
        ->assertUnprocessable()->assertJsonValidationErrors(['month_of_year']);
});

it('keeps an archived category or account that the template already uses', function () {
    $s = templateSetup();
    $template = PlanTemplate::factory()->forUser($s['user'])->create(['category_id' => $s['bills']->id, 'account_id' => $s['bca']->id]);
    $s['bills']->update(['archived_at' => now()]);
    $s['bca']->update(['archived_at' => now()]);

    actingAs($s['user'])->patchJson("/api/plan-templates/{$template->id}", ['planned_amount' => 600_000])
        ->assertOk()->assertJsonPath('data.planned_amount', 600_000);
});

it('refuses two active envelope templates that would land in the same month', function (array $first, array $second, bool $clash) {
    $s = templateSetup();
    $body = fn (array $o) => templateBody($s, ['mode' => 'envelope', 'name' => 'Makan', 'category_id' => $s['food']->id, 'day_of_month' => null, 'account_id' => null] + $o);
    actingAs($s['user'])->postJson('/api/plan-templates', $body($first))->assertCreated();

    $response = actingAs($s['user'])->postJson('/api/plan-templates', $body($second));

    $clash ? $response->assertUnprocessable()->assertJsonValidationErrors(['category_id']) : $response->assertCreated();
})->with([
    'monthly + monthly' => [[], [], true],
    'monthly + yearly' => [[], ['frequency' => 'yearly', 'month_of_year' => 12], true],
    'yearly Dec + yearly Dec' => [['frequency' => 'yearly', 'month_of_year' => 12], ['frequency' => 'yearly', 'month_of_year' => 12], true],
    'yearly Jun + yearly Dec' => [['frequency' => 'yearly', 'month_of_year' => 6], ['frequency' => 'yearly', 'month_of_year' => 12], false],
    'inactive monthly + monthly' => [['is_active' => false], [], false],
    'monthly + inactive monthly' => [[], ['is_active' => false], false],
]);

it('refuses to reactivate an envelope template that now clashes', function () {
    $s = templateSetup();
    $old = PlanTemplate::factory()->forUser($s['user'])->envelope()->inactive()->create(['category_id' => $s['food']->id]);
    PlanTemplate::factory()->forUser($s['user'])->envelope()->create(['category_id' => $s['food']->id]);

    actingAs($s['user'])->patchJson("/api/plan-templates/{$old->id}", ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['category_id']);
    // Mengubah template amplop aktif itu sendiri (tanpa tabrakan dengan dirinya) tetap boleh.
    actingAs($s['user'])->patchJson('/api/plan-templates/'.PlanTemplate::ownedBy($s['user'])->where('is_active', true)->value('id'), ['planned_amount' => 1])
        ->assertOk();
});

it('does not change plan items already generated when the template changes or is deleted', function () {
    $s = templateSetup();
    $template = PlanTemplate::factory()->forUser($s['user'])->create(['name' => 'Listrik', 'category_id' => $s['bills']->id, 'planned_amount' => 500_000]);
    $item = PlanItem::factory()->forUser($s['user'])->forMonth('2026-11')->create([
        'name' => 'Listrik', 'category_id' => $s['bills']->id, 'planned_amount' => 500_000, 'due_on' => '2026-11-05',
        'template_id' => $template->id, 'source' => PlanItemSource::Template,
    ]);
    $before = $item->fresh()?->only(['name', 'planned_amount', 'due_on', 'category_id', 'account_id']);

    actingAs($s['user'])->patchJson("/api/plan-templates/{$template->id}", ['name' => 'PLN', 'planned_amount' => 750_000, 'day_of_month' => 28])->assertOk();
    expect($item->fresh()?->only(['name', 'planned_amount', 'due_on', 'category_id', 'account_id']))->toEqual($before);

    actingAs($s['user'])->deleteJson("/api/plan-templates/{$template->id}")->assertNoContent();
    expect(PlanTemplate::ownedBy($s['user'])->count())->toBe(0)
        ->and($item->fresh()?->template_id)->toBeNull()
        ->and($item->fresh()?->name)->toBe('Listrik')
        ->and($item->fresh()?->source)->toBe(PlanItemSource::Template);
});

it('enforces the template rules with CHECK constraints in the database', function (array $attrs, string $constraint) {
    $s = templateSetup();

    expect(fn () => DB::table('plan_templates')->insert(array_merge([
        'user_id' => $s['user']->id, 'type' => 'expense', 'mode' => 'checklist', 'name' => 'X', 'category_id' => $s['bills']->id,
        'planned_amount' => 1, 'frequency' => 'monthly', 'day_of_month' => 1, 'month_of_year' => null, 'auto_post' => false, 'is_active' => true,
    ], $attrs)))->toThrow(QueryException::class, $constraint);
})->with([
    'amount' => [['planned_amount' => 0], 'chk_tpl_amount'],
    'day' => [['day_of_month' => 32], 'chk_tpl_day'],
    'envelope day' => [['mode' => 'envelope'], 'chk_tpl_day_mode'],
    'yearly without month' => [['frequency' => 'yearly'], 'chk_tpl_month'],
    'monthly with month' => [['month_of_year' => 2], 'chk_tpl_month'],
    'auto-post without account' => [['auto_post' => true], 'chk_tpl_auto'],
    'auto-post envelope' => [['mode' => 'envelope', 'day_of_month' => null, 'auto_post' => true], 'chk_tpl_auto'],
]);
