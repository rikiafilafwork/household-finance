<?php

use App\Enums\HouseholdRole;
use App\Jobs\CheckBudgetThresholds;
use App\Models\Budget;
use App\Models\BudgetAlert;
use App\Models\Category;
use App\Models\Household;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\BudgetThresholdReached;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * @return array{household: Household, owner: User, member: User, wallet: Wallet, food: Category, budget: Budget}
 */
function thresholdSetup(string $month = '2026-11'): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create(['name' => 'Keluarga Uji']);
    $household->members()->attach($member, ['role' => HouseholdRole::Member, 'joined_at' => now()]);
    $wallet = Wallet::factory()->for($household)->opening(10_000_000)->create();
    $food = Category::factory()->for($household)->create(['name' => 'Makan']);
    $budget = Budget::factory()->for($household)->forMonth($month)->create(['category_id' => $food->id, 'limit_amount' => 1_000_000]);

    return compact('household', 'owner', 'member', 'wallet', 'food', 'budget');
}

/**
 * @param  array{household: Household, owner: User, member: User, wallet: Wallet, food: Category, budget: Budget}  $s
 * @param  array<string, mixed>  $overrides
 */
function postExpense(array $s, int $amount, string $on, array $overrides = []): int
{
    return actingAs($s['owner'])->postJson("/api/households/{$s['household']->id}/transactions", [
        'wallet_id' => $s['wallet']->id, 'category_id' => $s['food']->id, 'type' => 'expense',
        'amount' => $amount, 'occurred_on' => $on, 'client_id' => (string) Str::uuid(), ...$overrides,
    ])->assertCreated()->json('data.id');
}

describe('dispatch', function () {
    beforeEach(fn () => Queue::fake());

    it('dispatches for the category and month of a posted expense', function () {
        $s = thresholdSetup();
        postExpense($s, 10_000, '2026-11-17');

        Queue::assertPushed(CheckBudgetThresholds::class, fn (CheckBudgetThresholds $job) => $job->householdId === $s['household']->id
            && $job->categoryId === $s['food']->id
            && $job->month === '2026-11-01');
    });

    it('uses the month of occurred_on for backdated expenses, also when edited into last month', function () {
        $s = thresholdSetup();
        postExpense($s, 10_000, '2026-10-31');
        Queue::assertPushed(CheckBudgetThresholds::class, fn ($job) => $job->month === '2026-10-01');

        $id = postExpense($s, 10_000, '2026-11-05');
        actingAs($s['owner'])->patchJson("/api/households/{$s['household']->id}/transactions/{$id}", ['occurred_on' => '2026-09-30'])->assertOk();
        Queue::assertPushed(CheckBudgetThresholds::class, fn ($job) => $job->month === '2026-09-01');
    });

    it('does not dispatch for income, uncategorized expense, private wallets, transfers, or adjustments', function () {
        $s = thresholdSetup();
        $private = Wallet::factory()->for($s['household'])->privateTo($s['owner'])->opening(1_000_000)->create();
        $salary = Category::factory()->for($s['household'])->income()->create();
        $base = "/api/households/{$s['household']->id}";

        postExpense($s, 5_000, '2026-11-02', ['type' => 'income', 'category_id' => $salary->id]);
        postExpense($s, 5_000, '2026-11-02', ['category_id' => null]);
        postExpense($s, 5_000, '2026-11-02', ['wallet_id' => $private->id]);
        actingAs($s['owner'])->postJson("{$base}/transfers", [
            'from_wallet_id' => $s['wallet']->id, 'to_wallet_id' => $private->id, 'amount' => 1_000, 'occurred_on' => '2026-11-02', 'client_id' => (string) Str::uuid(),
        ])->assertCreated();
        actingAs($s['owner'])->postJson("{$base}/wallets/{$s['wallet']->id}/adjust", ['actual_balance' => 1, 'occurred_on' => '2026-11-02'])->assertCreated();

        Queue::assertNotPushed(CheckBudgetThresholds::class);
    });

    it('does not dispatch for a replayed client_id', function () {
        $s = thresholdSetup();
        $clientId = (string) Str::uuid();
        postExpense($s, 5_000, '2026-11-02', ['client_id' => $clientId]);
        actingAs($s['owner'])->postJson("/api/households/{$s['household']->id}/transactions", [
            'wallet_id' => $s['wallet']->id, 'category_id' => $s['food']->id, 'type' => 'expense',
            'amount' => 5_000, 'occurred_on' => '2026-11-02', 'client_id' => $clientId,
        ])->assertOk();

        Queue::assertPushedTimes(CheckBudgetThresholds::class, 1);
    });

    it('dispatches only after the DB transaction commits (never for a rolled-back write)', function () {
        $s = thresholdSetup();
        $s['food']->update(['archived_at' => now()]); // validasi gagal di dalam transaksi DB → rollback

        actingAs($s['owner'])->postJson("/api/households/{$s['household']->id}/transactions", [
            'wallet_id' => $s['wallet']->id, 'category_id' => $s['food']->id, 'type' => 'expense',
            'amount' => 5_000, 'occurred_on' => '2026-11-02', 'client_id' => (string) Str::uuid(),
        ])->assertUnprocessable();

        Queue::assertNotPushed(CheckBudgetThresholds::class);
        expect(new CheckBudgetThresholds(1, 1, '2026-11-01'))->toBeInstanceOf(ShouldQueueAfterCommit::class);
    });

    it('re-checks the month after the owner lowers a limit', function () {
        $s = thresholdSetup();
        actingAs($s['owner'])->putJson("/api/households/{$s['household']->id}/budgets/2026-11", ['items' => [
            ['category_id' => $s['food']->id, 'limit_amount' => 100],
        ]])->assertOk();

        Queue::assertPushed(CheckBudgetThresholds::class, fn ($job) => $job->categoryId === $s['food']->id && $job->month === '2026-11-01');
    });
});

describe('job + notification (acceptance: stored + emailed)', function () {
    it('stores a database notification and sends an email to every member when 80% is reached', function () {
        Notification::fake();
        $s = thresholdSetup();

        postExpense($s, 800_000, '2026-11-10');

        $alert = BudgetAlert::sole();
        expect($alert->threshold)->toBe(80)->and($alert->spent_amount)->toBe(800_000);
        foreach ([$s['owner'], $s['member']] as $user) {
            Notification::assertSentTo($user, BudgetThresholdReached::class, function (BudgetThresholdReached $n, array $channels) use ($user) {
                $data = $n->toArray($user);

                return $channels === ['database', 'mail'] && $n->threshold === 80
                    && $data['category']['name'] === 'Makan' && $data['month'] === '2026-11'
                    && $data['spent'] === 800_000 && $data['limit_amount'] === 1_000_000 && $data['percent'] === 80;
            });
        }
        Notification::assertSentTimes(BudgetThresholdReached::class, 2);
    });

    it('writes the notification row and renders the email (real channels)', function () {
        Mail::fake();
        $s = thresholdSetup();

        postExpense($s, 1_050_000, '2026-11-10');

        $row = DatabaseNotification::query()->where('notifiable_id', $s['member']->id)->sole();
        expect($row->type)->toBe(BudgetThresholdReached::TYPE)
            ->and($row->data['threshold'])->toBe(100)
            ->and($row->data['household_id'])->toBe($s['household']->id);

        $mail = (new BudgetThresholdReached($s['budget']->fresh(), 100, 1_050_000))->toMail($s['owner']);
        $html = (string) $mail->render();
        expect($mail->subject)->toBe('Budget Makan November 2026 sudah habis')
            ->and($html)->toContain('Rp1.050.000')->toContain('Rp1.000.000')->toContain('105%')
            ->and($mail->actionUrl)->toEndWith('/budgets/2026-11');
    });

    it('records both thresholds on a jump past 100% but sends a single notification', function () {
        Notification::fake();
        $s = thresholdSetup();

        postExpense($s, 1_200_000, '2026-11-10');

        expect(BudgetAlert::orderBy('threshold')->pluck('threshold')->all())->toBe([80, 100]);
        Notification::assertSentToTimes($s['owner'], BudgetThresholdReached::class, 1);
        Notification::assertSentTo($s['owner'], BudgetThresholdReached::class, fn ($n) => $n->threshold === 100);
    });

    it('does nothing for a category without a budget that month', function () {
        Notification::fake();
        $s = thresholdSetup('2026-10');

        postExpense($s, 5_000_000, '2026-11-10');

        expect(BudgetAlert::count())->toBe(0);
        Notification::assertNothingSent();
    });

    it('is safe to run twice for the same budget (unique index, no second notification)', function () {
        Notification::fake();
        $s = thresholdSetup();
        postExpense($s, 900_000, '2026-11-10');

        (new CheckBudgetThresholds($s['household']->id, $s['food']->id, '2026-11-01'))->handle();
        (new CheckBudgetThresholds($s['household']->id, $s['food']->id, '2026-11-01'))->handle();

        expect(BudgetAlert::count())->toBe(1);
        Notification::assertSentToTimes($s['owner'], BudgetThresholdReached::class, 1);
    });
});
