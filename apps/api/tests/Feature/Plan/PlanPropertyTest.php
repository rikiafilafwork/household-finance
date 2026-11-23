<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\Transaction;
use App\Models\User;
use App\Plan\PlanMonth;
use App\Queries\PlanComparisonQuery;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

/*
| #130 — test properti (Architecture.md § Minggu 3, "Done kalau"): rangkaian acak centang,
| centang ulang, batal centang, edit & hapus transaksi terhubung, transaksi manual dan transfer.
| Setelah SETIAP langkah:
|   1. `accounts:reconcile` (command sungguhan) melaporkan selisih 0;
|   2. saldo tiap akun = model independen di test ini (menangkap bug yang membuat saldo dan
|      ledger salah bersama-sama, yang lolos dari reconcile);
|   3. status & aktual tiap item serta aktual amplop dari PlanComparisonQuery = model.
| Seed tercetak; ulangi kegagalan dengan PLAN_SEED=<seed> php artisan test --filter=PlanProperty.
*/

final class PlanLedgerModel
{
    public User $user;

    /** @var list<Account> */
    public array $accounts = [];

    /** @var list<int> saldo yang diharapkan, per indeks akun */
    public array $expected = [];

    /** @var array<string, PlanItem> */
    public array $items = [];

    /** @var array<string, array{tx: int, account: int, amount: int, client_id: string}|null> */
    public array $linked = [];

    /** @var array<int, array{account: int, amount: int, food: bool}> transaksi manual aktif */
    public array $manual = [];

    /** @var array<string, array{from: int, to: int, amount: int}> */
    public array $transfers = [];

    public PlanItem $envelope;

    public Category $food;

    public Category $salaryCat;

    public function __construct()
    {
        $this->user = User::factory()->create();
        foreach ([5_000_000, 300_000, 0] as $i => $opening) {
            $this->accounts[] = Account::factory()->forUser($this->user)->opening($opening)->create(['name' => "Akun {$i}"]);
            $this->expected[] = $opening;
        }
        $bills = Category::factory()->forUser($this->user)->create(['name' => 'Tagihan']);
        $this->food = Category::factory()->forUser($this->user)->create(['name' => 'Makan']);
        $this->salaryCat = Category::factory()->forUser($this->user)->income()->create(['name' => 'Gaji']);

        $this->envelope = PlanItem::factory()->forUser($this->user)->envelope()->create([
            'name' => 'Makan', 'category_id' => $this->food->id, 'planned_amount' => 2_000_000,
        ]);
        foreach ([
            'Listrik' => ['expense', $bills, 500_000],
            'Internet' => ['expense', $bills, 350_000],
            'Katering' => ['expense', $this->food, 300_000], // kategori sama dengan amplop
            'Gaji' => ['income', $this->salaryCat, 8_000_000],
        ] as $name => [$type, $category, $planned]) {
            $this->items[$name] = PlanItem::factory()->forUser($this->user)->create([
                'name' => $name, 'type' => $type, 'category_id' => $category->id, 'planned_amount' => $planned, 'account_id' => $this->accounts[0]->id,
            ]);
            $this->linked[$name] = null;
        }
    }

    public function check(string $name, int $account, int $amount, string $date): void
    {
        $item = $this->items[$name];
        $clientId = (string) Str::uuid();
        $response = actingAs($this->user)->postJson("/api/plan-items/{$item->id}/check", [
            'client_id' => $clientId, 'account_id' => $this->accounts[$account]->id, 'amount' => $amount, 'occurred_on' => $date,
        ]);

        if ($this->linked[$name] !== null) {
            $response->assertConflict(); // sudah dicentang dari "perangkat lain"

            return;
        }

        $response->assertCreated();
        $signed = $item->type->value === 'income' ? $amount : -$amount;
        $this->linked[$name] = ['tx' => (int) $response->json('data.transaction.id'), 'account' => $account, 'amount' => $signed, 'client_id' => $clientId];
        $this->expected[$account] += $signed;
    }

    /** Retry dengan client_id yang sama (jaringan putus setelah server menyimpan) → 200, tanpa efek. */
    public function retryCheck(string $name): void
    {
        $link = $this->linked[$name];
        if ($link === null) {
            return;
        }
        actingAs($this->user)->postJson("/api/plan-items/{$this->items[$name]->id}/check", ['client_id' => $link['client_id']])
            ->assertOk()
            ->assertJsonPath('data.transaction.id', $link['tx']);
    }

    public function uncheck(string $name): void
    {
        actingAs($this->user)->postJson("/api/plan-items/{$this->items[$name]->id}/uncheck")->assertOk()->assertJsonPath('data.status', 'planned');
        $this->forget($name);
    }

    public function deleteLinked(string $name): void
    {
        if ($this->linked[$name] === null) {
            return;
        }
        actingAs($this->user)->deleteJson("/api/transactions/{$this->linked[$name]['tx']}")->assertNoContent();
        $this->forget($name);
    }

    public function editLinked(string $name, int $account, int $amount, string $date): void
    {
        $link = $this->linked[$name];
        if ($link === null) {
            return;
        }
        actingAs($this->user)->patchJson("/api/transactions/{$link['tx']}", [
            'account_id' => $this->accounts[$account]->id, 'amount' => $amount, 'occurred_on' => $date,
        ])->assertOk();

        $signed = $link['amount'] < 0 ? -$amount : $amount;
        $this->expected[$link['account']] -= $link['amount'];
        $this->expected[$account] += $signed;
        $this->linked[$name] = ['account' => $account, 'amount' => $signed] + $link;
    }

    public function spend(int $account, int $amount, bool $food, string $date): void
    {
        $id = (int) actingAs($this->user)->postJson('/api/transactions', [
            'account_id' => $this->accounts[$account]->id, 'type' => 'expense', 'amount' => $amount, 'occurred_on' => $date,
            'category_id' => $food ? $this->food->id : null, 'client_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');

        // Hanya pengeluaran Makan di November yang masuk amplop November.
        $this->manual[$id] = ['account' => $account, 'amount' => -$amount, 'food' => $food && str_starts_with($date, '2026-11')];
        $this->expected[$account] -= $amount;
    }

    public function deleteManual(int $id): void
    {
        actingAs($this->user)->deleteJson("/api/transactions/{$id}")->assertNoContent();
        $this->expected[$this->manual[$id]['account']] -= $this->manual[$id]['amount'];
        unset($this->manual[$id]);
    }

    public function transfer(int $from, int $to, int $amount): void
    {
        $group = (string) actingAs($this->user)->postJson('/api/transfers', [
            'from_account_id' => $this->accounts[$from]->id, 'to_account_id' => $this->accounts[$to]->id,
            'amount' => $amount, 'occurred_on' => '2026-11-15', 'client_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data.transfer_group_id');

        $this->transfers[$group] = ['from' => $from, 'to' => $to, 'amount' => $amount];
        $this->expected[$from] -= $amount;
        $this->expected[$to] += $amount;
    }

    public function deleteTransfer(string $group): void
    {
        actingAs($this->user)->deleteJson("/api/transfers/{$group}")->assertNoContent();
        $t = $this->transfers[$group];
        $this->expected[$t['from']] += $t['amount'];
        $this->expected[$t['to']] -= $t['amount'];
        unset($this->transfers[$group]);
    }

    private function forget(string $name): void
    {
        if ($this->linked[$name] !== null) {
            $this->expected[$this->linked[$name]['account']] -= $this->linked[$name]['amount'];
        }
        $this->linked[$name] = null;
    }

    public function assertInvariants(string $step): void
    {
        artisan('accounts:reconcile --no-notify')->expectsOutputToContain('Selisih 0')->assertSuccessful();

        foreach ($this->accounts as $i => $account) {
            expect($account->fresh()?->balance)->toBe($this->expected[$i], "{$step}: saldo akun {$i}");
        }

        $plan = app(PlanComparisonQuery::class)->for($this->user, PlanMonth::parse('2026-11'));
        foreach ($this->items as $name => $item) {
            $row = $plan->item($item->id);
            $link = $this->linked[$name];
            expect($row?->status())->toBe($link === null ? 'planned' : 'done', "{$step}: status {$name}")
                ->and($row?->actual)->toBe($link === null ? 0 : abs($link['amount']), "{$step}: aktual {$name}");
        }
        $envelopeActual = -array_sum(array_map(fn (array $m) => $m['food'] ? $m['amount'] : 0, $this->manual));
        expect($plan->item($this->envelope->id)?->actual)->toBe($envelopeActual, "{$step}: aktual amplop Makan (tanpa transaksi centang)");

        $linkedCount = count(array_filter($this->linked));
        expect(Transaction::ownedBy($this->user)->whereNotNull('plan_item_id')->count())->toBe($linkedCount, "{$step}: transaksi terhubung aktif")
            ->and(Transaction::ownedBy($this->user)->onlyTrashed()->whereNotNull('plan_item_id')->count())->toBe(0, "{$step}: transaksi terhapus masih memegang plan_item_id");
    }
}

it('keeps reconcile at 0 and the plan numbers right through random check/uncheck/edit/delete sequences', function (?int $fixedSeed) {
    $seed = $fixedSeed ?? (int) (getenv('PLAN_SEED') ?: random_int(1, PHP_INT_MAX));
    mt_srand($seed);
    fwrite(STDERR, "\n  plan seed: {$seed}\n");

    $m = new PlanLedgerModel;
    $names = array_keys($m->items);
    $pick = fn (array $xs) => $xs[mt_rand(0, count($xs) - 1)];
    $account = fn () => mt_rand(0, count($m->accounts) - 1);
    $date = fn () => sprintf('2026-%02d-%02d', mt_rand(10, 12), mt_rand(1, 28)); // tagihan bisa dibayar di luar bulannya
    $ops = ['check', 'check', 'check', 'retry', 'uncheck', 'uncheck', 'deleteLinked', 'editLinked', 'spend', 'spend', 'deleteManual', 'transfer', 'deleteTransfer'];

    for ($i = 1; $i <= 120; $i++) {
        $op = $pick($ops);
        $name = $pick($names);

        match ($op) {
            'check' => $m->check($name, $account(), mt_rand(1, 3_000_000), $date()),
            'retry' => $m->retryCheck($name),
            'uncheck' => $m->uncheck($name),
            'deleteLinked' => $m->deleteLinked($name),
            'editLinked' => $m->editLinked($name, $account(), mt_rand(1, 3_000_000), $date()),
            'spend' => $m->spend($account(), mt_rand(1, 500_000), mt_rand(0, 2) > 0, $date()),
            'deleteManual' => $m->manual === [] ? null : $m->deleteManual($pick(array_keys($m->manual))),
            'transfer' => (function () use ($m, $account) {
                $from = $account();
                $m->transfer($from, ($from + mt_rand(1, count($m->accounts) - 1)) % count($m->accounts), mt_rand(1, 1_000_000));
            })(),
            'deleteTransfer' => $m->transfers === [] ? null : $m->deleteTransfer($pick(array_keys($m->transfers))),
            default => throw new LogicException("Operasi tidak dikenal: {$op}"),
        };

        $m->assertInvariants("seed {$seed}, langkah {$i} ({$op} {$name})");
    }

    // Rangkaian benar-benar menjelajah: ada yang dicentang ulang setelah dibatalkan/dihapus.
    expect(Transaction::ownedBy($m->user)->onlyTrashed()->count())->toBeGreaterThan(0);
})->with([
    'seed tetap 20261123' => [20261123],
    'seed tetap 130' => [130],
    'seed acak' => [null],
])->group('slow');
