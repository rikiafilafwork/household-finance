<?php

use App\Enums\HouseholdRole;
use App\Models\Budget;
use App\Models\BudgetAlert;
use App\Models\Category;
use App\Models\Household;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\BudgetThresholdReached;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/*
| #29, Architecture.md § Minggu 3 (Test). Alur penuh tanpa fake: API → Action → job (queue sync,
| afterCommit) → budget_alerts → notifikasi database. Yang dihitung adalah BARIS di DB, bukan
| panggilan yang di-mock, supaya "tepat sekali per ambang" benar-benar terbukti.
|
| Budget Makan Rp1.000.000 di wallet shared; satu owner + satu member (dua penerima).
*/

beforeEach(fn () => Mail::fake());

final class AlertScenario
{
    public function __construct(
        public Household $household,
        public User $owner,
        public User $member,
        public Wallet $shared,
        public Wallet $private,
        public Category $food,
        public Budget $budget,
    ) {}

    public static function make(int $limit = 1_000_000): self
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $household = Household::factory()->withOwner($owner)->create();
        $household->members()->attach($member, ['role' => HouseholdRole::Member, 'joined_at' => now()]);
        $food = Category::factory()->for($household)->create(['name' => 'Makan']);

        return new self(
            $household, $owner, $member,
            Wallet::factory()->for($household)->opening(50_000_000)->create(['name' => 'BCA Bersama']),
            Wallet::factory()->for($household)->privateTo($member)->opening(50_000_000)->create(['name' => 'Dompet Member']),
            $food,
            Budget::factory()->for($household)->forMonth('2026-11')->create(['category_id' => $food->id, 'limit_amount' => $limit]),
        );
    }

    public function spend(int $amount, ?Wallet $wallet = null, string $on = '2026-11-15', ?User $actor = null): int
    {
        $wallet ??= $this->shared;
        $actor ??= $wallet->isPrivate() ? $this->member : $this->owner;

        return actingAs($actor)->postJson("/api/households/{$this->household->id}/transactions", [
            'wallet_id' => $wallet->id, 'category_id' => $this->food->id, 'type' => 'expense',
            'amount' => $amount, 'occurred_on' => $on, 'client_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');
    }

    /** @return list<int> */
    public function alerts(): array
    {
        /** @var list<int> */
        return BudgetAlert::query()->where('budget_id', $this->budget->id)->orderBy('threshold')->pluck('threshold')->all();
    }

    /**
     * Ambang di notifikasi milik $user, diurutkan (created_at hanya presisi detik dan id-nya UUID,
     * jadi urutan waktu dua notifikasi di detik yang sama tidak bisa diandalkan).
     *
     * @return list<int>
     */
    public function notified(User $user): array
    {
        /** @var list<int> */
        return DatabaseNotification::query()
            ->where('notifiable_id', $user->id)
            ->where('type', BudgetThresholdReached::TYPE)
            ->get()->map(fn (DatabaseNotification $n) => $n->data['threshold'])->sort()->values()->all();
    }

    public function percent(): int
    {
        return actingAs($this->owner)->getJson("/api/households/{$this->household->id}/budgets/2026-11")
            ->assertOk()->json('data.items.0.percent');
    }
}

it('sends no alert at 79%', function () {
    $s = AlertScenario::make();

    $s->spend(500_000);
    $s->spend(290_000);
    $s->spend(9_999); // 799.999 = 79,9999%

    expect($s->percent())->toBe(79)
        ->and($s->alerts())->toBe([])
        ->and($s->notified($s->owner))->toBe([])
        ->and($s->notified($s->member))->toBe([]);
});

it('sends exactly one alert when crossing 80%', function () {
    $s = AlertScenario::make();
    $s->spend(799_999);

    $s->spend(1); // tepat 80%

    expect($s->alerts())->toBe([80])
        ->and($s->notified($s->owner))->toBe([80])
        ->and($s->notified($s->member))->toBe([80]);
});

it('keeps a single alert while later expenses stay within 80–99%', function () {
    $s = AlertScenario::make();
    $s->spend(810_000); // 81% → alert 80

    foreach ([50_000, 60_000, 50_000, 29_999] as $amount) { // 86%, 92%, 97%, 99,9999%
        $s->spend($amount);
    }

    expect($s->percent())->toBe(99)
        ->and($s->alerts())->toBe([80])
        ->and($s->notified($s->owner))->toBe([80])
        ->and($s->notified($s->member))->toBe([80]);
});

it('sends the second alert when crossing 100%, and nothing after that', function () {
    $s = AlertScenario::make();
    $s->spend(850_000); // 85% → alert 80
    $s->spend(150_000); // tepat 100% → alert 100

    $s->spend(300_000); // 130%
    $s->spend(1);

    expect($s->alerts())->toBe([80, 100])
        ->and($s->notified($s->owner))->toBe([80, 100])
        ->and($s->notified($s->member))->toBe([80, 100])
        ->and(BudgetAlert::where('threshold', 100)->value('spent_amount'))->toBe(1_000_000); // snapshot saat alert
});

it('does not move the budget with expenses in a private wallet', function () {
    $s = AlertScenario::make();

    $s->spend(5_000_000, $s->private); // 500% kalau dihitung, tapi wallet privat member

    expect($s->percent())->toBe(0)
        ->and($s->alerts())->toBe([])
        ->and($s->notified($s->owner))->toBe([])
        ->and($s->notified($s->member))->toBe([]);

    // Dan tidak ikut "mendorong": 79% shared + privat besar tetap tanpa alert.
    $s->spend(790_000);
    $s->spend(500_000, $s->private);
    expect($s->percent())->toBe(79)->and($s->alerts())->toBe([]);
});

it('alerts last month\'s budget for a backdated expense, not this month\'s', function () {
    $s = AlertScenario::make();
    $october = Budget::factory()->for($s->household)->forMonth('2026-10')->create(['category_id' => $s->food->id, 'limit_amount' => 100_000]);

    $s->spend(90_000, on: '2026-10-31');

    expect(BudgetAlert::where('budget_id', $october->id)->pluck('threshold')->all())->toBe([80])
        ->and($s->alerts())->toBe([]);
});

it('alerts when an edit pushes an expense over a threshold (amount up, or moved into the month)', function () {
    $s = AlertScenario::make();
    $id = $s->spend(700_000);
    $url = "/api/households/{$s->household->id}/transactions/{$id}";

    actingAs($s->owner)->patchJson($url, ['amount' => 820_000])->assertOk();
    expect($s->alerts())->toBe([80]);

    $other = $s->spend(200_000, on: '2026-12-02');
    actingAs($s->owner)->patchJson("/api/households/{$s->household->id}/transactions/{$other}", ['occurred_on' => '2026-11-30'])->assertOk();
    expect($s->alerts())->toBe([80, 100]);
});

it('does not re-alert after deleting and re-adding an expense (known limitation: no re-arm)', function () {
    $s = AlertScenario::make();
    $id = $s->spend(850_000);
    actingAs($s->owner)->deleteJson("/api/households/{$s->household->id}/transactions/{$id}")->assertNoContent();

    $s->spend(850_000);

    expect($s->alerts())->toBe([80])->and($s->notified($s->owner))->toBe([80]);
});
