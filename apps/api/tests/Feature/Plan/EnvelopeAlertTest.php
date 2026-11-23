<?php

use App\Models\Account;
use App\Models\BudgetAlert;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\User;
use App\Notifications\EnvelopeThresholdReached;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/*
| Keputusan #10: ambang 80 dan 100, masing-masing tepat sekali per amplop; notifikasi in-app saja.
*/

final class AlertFixture
{
    public User $user;

    public Account $bca;

    public Category $food;

    public PlanItem $envelope;

    public function __construct(int $planned = 1_000_000, string $month = '2026-11')
    {
        $this->user = User::factory()->create();
        $this->bca = Account::factory()->forUser($this->user)->opening(50_000_000)->create();
        $this->food = Category::factory()->forUser($this->user)->create(['name' => 'Makan']);
        $this->envelope = PlanItem::factory()->forUser($this->user)->forMonth($month)->envelope()
            ->create(['name' => 'Makan', 'category_id' => $this->food->id, 'planned_amount' => $planned]);
    }

    public function spend(int $amount, string $date = '2026-11-15', ?Category $category = null): int
    {
        return (int) actingAs($this->user)->postJson('/api/transactions', [
            'account_id' => $this->bca->id, 'type' => 'expense', 'amount' => $amount, 'occurred_on' => $date,
            'category_id' => ($category ?? $this->food)->id, 'client_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');
    }

    /** @return list<int> */
    public function thresholds(?PlanItem $envelope = null): array
    {
        return BudgetAlert::where('plan_item_id', ($envelope ?? $this->envelope)->id)->orderBy('threshold')->pluck('threshold')->all();
    }

    /** @return list<int> */
    public function notified(): array
    {
        return DatabaseNotification::query()
            ->where('notifiable_id', $this->user->id)->where('type', EnvelopeThresholdReached::TYPE)
            ->get()
            ->map(fn (DatabaseNotification $n) => (int) $n->data['threshold'])
            ->sort()->values()->all(); // created_at bisa sama detiknya; id UUID tidak berurutan
    }
}

it('follows the acceptance sequence: 79% none, 80% one, 80–99% still one, 100% a second', function () {
    $f = new AlertFixture(1_000_000);

    $f->spend(790_000);
    expect($f->thresholds())->toBe([])->and($f->notified())->toBe([]);

    $f->spend(10_000); // 800.000 = 80%
    expect($f->thresholds())->toBe([80])->and($f->notified())->toBe([80]);

    $f->spend(150_000); // 950.000 = 95%
    expect($f->thresholds())->toBe([80])->and($f->notified())->toBe([80]);

    $f->spend(50_000); // 1.000.000 = 100%
    expect($f->thresholds())->toBe([80, 100])->and($f->notified())->toBe([80, 100]);

    $f->spend(300_000); // 130%
    expect($f->notified())->toBe([80, 100]);
});

it('records both thresholds but sends one notification when one expense jumps past both', function () {
    $f = new AlertFixture(1_000_000);
    $f->spend(500_000);

    $f->spend(700_000); // 50% → 120%

    expect($f->thresholds())->toBe([80, 100])
        ->and($f->notified())->toBe([100])
        ->and(BudgetAlert::where('plan_item_id', $f->envelope->id)->pluck('spent_amount')->unique()->all())->toBe([1_200_000]);
});

it('stores an in-app notification with the envelope numbers, shown in the bell', function () {
    $f = new AlertFixture(1_000_000);
    $f->spend(850_000);

    actingAs($f->user)->getJson('/api/notifications')
        ->assertJsonPath('meta.unread_count', 1)
        ->assertJsonPath('data.0.type', 'envelope_threshold_reached')
        ->assertJsonPath('data.0.payload', [
            'plan_item_id' => $f->envelope->id, 'name' => 'Makan', 'category_id' => $f->food->id, 'month' => '2026-11',
            'threshold' => 80, 'spent' => 850_000, 'planned' => 1_000_000, 'percent' => 85,
        ]);
});

it('checks the envelope of the transaction’s own month when backdated', function () {
    $f = new AlertFixture(1_000_000, '2026-10');
    $november = PlanItem::factory()->forUser($f->user)->forMonth('2026-11')->envelope()
        ->create(['name' => 'Makan', 'category_id' => $f->food->id, 'planned_amount' => 1_000_000]);

    $f->spend(900_000, '2026-10-28');

    expect($f->thresholds())->toBe([80])->and($f->thresholds($november))->toBe([]);
});

it('re-checks when an expense is edited into the envelope (new category or month)', function () {
    $f = new AlertFixture(1_000_000);
    $other = Category::factory()->forUser($f->user)->create();
    $id = $f->spend(900_000, '2026-11-15', $other);
    expect($f->thresholds())->toBe([]);

    actingAs($f->user)->patchJson("/api/transactions/{$id}", ['category_id' => $f->food->id])->assertOk();

    expect($f->thresholds())->toBe([80]);
});

it('re-checks when the envelope itself is created or lowered below what is already spent', function () {
    $f = new AlertFixture(2_000_000);
    $f->spend(900_000); // 45%
    expect($f->thresholds())->toBe([]);

    actingAs($f->user)->patchJson("/api/plan-items/{$f->envelope->id}", ['planned_amount' => 1_000_000])->assertOk(); // 90%
    expect($f->thresholds())->toBe([80]);

    $transport = Category::factory()->forUser($f->user)->create();
    $f->spend(600_000, '2026-11-15', $transport);
    $id = actingAs($f->user)->postJson('/api/plan/2026-11/items', [
        'type' => 'expense', 'mode' => 'envelope', 'name' => 'Transport', 'category_id' => $transport->id, 'planned_amount' => 500_000,
    ])->assertCreated()->json('data.id');
    expect(BudgetAlert::where('plan_item_id', $id)->pluck('threshold')->sort()->values()->all())->toBe([80, 100]);
});

it('ignores checked checklist transactions, skipped envelopes, checklists and income', function () {
    $f = new AlertFixture(1_000_000);
    $katering = PlanItem::factory()->forUser($f->user)->create(['name' => 'Katering', 'category_id' => $f->food->id, 'planned_amount' => 950_000, 'account_id' => $f->bca->id]);

    // Transaksi centang di kategori amplop tidak masuk amplop → tidak memicu alert.
    actingAs($f->user)->postJson("/api/plan-items/{$katering->id}/check", ['client_id' => (string) Str::uuid(), 'occurred_on' => '2026-11-10'])->assertCreated();
    expect($f->thresholds())->toBe([]);

    // Amplop di-skip: tidak ada alert.
    actingAs($f->user)->postJson("/api/plan-items/{$f->envelope->id}/skip")->assertOk();
    $f->spend(990_000);
    expect($f->thresholds())->toBe([]);

    // Amplop pemasukan: tidak ada alert.
    $freelance = Category::factory()->forUser($f->user)->income()->create();
    PlanItem::factory()->forUser($f->user)->envelope()->create(['type' => 'income', 'name' => 'Freelance', 'category_id' => $freelance->id, 'planned_amount' => 100_000]);
    actingAs($f->user)->postJson('/api/transactions', [
        'account_id' => $f->bca->id, 'type' => 'income', 'amount' => 500_000, 'occurred_on' => '2026-11-15', 'category_id' => $freelance->id, 'client_id' => (string) Str::uuid(),
    ])->assertCreated();

    expect(BudgetAlert::count())->toBe(0)->and($f->notified())->toBe([]);
});

it('does not alert again after spending drops below and climbs back over a threshold', function () {
    $f = new AlertFixture(1_000_000);
    $id = $f->spend(850_000);
    actingAs($f->user)->deleteJson("/api/transactions/{$id}")->assertNoContent();

    $f->spend(820_000);

    expect($f->thresholds())->toBe([80])->and($f->notified())->toBe([80]);
});

it('only alerts the owner of the envelope', function () {
    $f = new AlertFixture(1_000_000);
    $other = new AlertFixture(1_000_000);

    $f->spend(900_000);

    expect($f->notified())->toBe([80])->and($other->notified())->toBe([])->and($other->thresholds())->toBe([]);
});
