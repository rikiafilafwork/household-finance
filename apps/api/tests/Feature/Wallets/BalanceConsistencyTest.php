<?php

use App\Enums\HouseholdRole;
use App\Models\Category;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

/*
| Keputusan #3 / ADR-002: setelah skenario tulis apa pun, `wallets:reconcile` (command sungguhan,
| bukan helper test) harus melaporkan selisih 0.
*/

/**
 * Klien API kecil untuk skenario saldo.
 */
final class LedgerClient
{
    public readonly Household $household;

    public readonly User $owner;

    /** @var list<Wallet> */
    public array $wallets = [];

    public readonly Category $food;

    public readonly Category $salary;

    public function __construct()
    {
        $this->owner = User::factory()->create();
        $member = User::factory()->create();
        $this->household = Household::factory()->withOwner($this->owner)->create();
        $this->household->members()->attach($member, ['role' => HouseholdRole::Member, 'joined_at' => now()]);

        foreach ([1_000_000, 250_000, 0, 5_000_000] as $i => $opening) {
            $this->wallets[] = Wallet::factory()->for($this->household)->opening($opening)->create(['name' => "W{$i}"]);
        }
        // Wallet private owner ikut diuji: reconcile mencakup semua wallet, terlihat atau tidak.
        $this->wallets[] = Wallet::factory()->for($this->household)->privateTo($this->owner)->opening(75_000)->create(['name' => 'Private']);

        $this->food = Category::factory()->for($this->household)->create();
        $this->salary = Category::factory()->for($this->household)->income()->create();
    }

    private function url(string $path): string
    {
        return "/api/households/{$this->household->id}/{$path}";
    }

    public function create(string $type, int $walletIndex, int $amount, string $date = '2026-11-21'): int
    {
        return (int) actingAs($this->owner)->postJson($this->url('transactions'), [
            'wallet_id' => $this->wallets[$walletIndex]->id,
            'type' => $type,
            'amount' => $amount,
            'occurred_on' => $date,
            'category_id' => $type === 'expense' ? $this->food->id : $this->salary->id,
            'client_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(int $id, array $changes): void
    {
        if (isset($changes['wallet'])) {
            $changes['wallet_id'] = $this->wallets[$changes['wallet']]->id;
            unset($changes['wallet']);
        }
        actingAs($this->owner)->patchJson($this->url("transactions/{$id}"), $changes)->assertOk();
    }

    public function delete(int $id): void
    {
        actingAs($this->owner)->deleteJson($this->url("transactions/{$id}"))->assertNoContent();
    }

    public function transfer(int $from, int $to, int $amount): string
    {
        return (string) actingAs($this->owner)->postJson($this->url('transfers'), [
            'from_wallet_id' => $this->wallets[$from]->id,
            'to_wallet_id' => $this->wallets[$to]->id,
            'amount' => $amount,
            'occurred_on' => '2026-11-21',
            'client_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data.transfer_group_id');
    }

    /**
     * @param  array<string, int>  $changes  from/to = indeks wallet, amount
     */
    public function updateTransfer(string $group, array $changes): void
    {
        $payload = [];
        if (isset($changes['from'])) {
            $payload['from_wallet_id'] = $this->wallets[$changes['from']]->id;
        }
        if (isset($changes['to'])) {
            $payload['to_wallet_id'] = $this->wallets[$changes['to']]->id;
        }
        if (isset($changes['amount'])) {
            $payload['amount'] = $changes['amount'];
        }
        actingAs($this->owner)->patchJson($this->url("transfers/{$group}"), $payload)->assertOk();
    }

    public function deleteTransfer(string $group): void
    {
        actingAs($this->owner)->deleteJson($this->url("transfers/{$group}"))->assertNoContent();
    }

    public function adjust(int $walletIndex, int $actual): void
    {
        actingAs($this->owner)->postJson($this->url("wallets/{$this->wallets[$walletIndex]->id}/adjust"), [
            'actual_balance' => $actual, 'occurred_on' => '2026-11-21',
        ])->assertSuccessful();
    }

    public function balance(int $walletIndex): int
    {
        return (int) $this->wallets[$walletIndex]->fresh()?->balance;
    }
}

function assertReconcileClean(): void
{
    artisan('wallets:reconcile --no-notify')->expectsOutputToContain('Selisih 0')->assertSuccessful();
}

it('stays consistent in every named scenario', function (Closure $scenario) {
    $ledger = new LedgerClient;

    // Catatan: parameter bertipe Closure → Pest meneruskan closure apa adanya (tidak dieksekusi).
    // Pengaman supaya skenario tidak diam-diam kosong seperti versi pertama test ini.
    $before = Transaction::withTrashed()->count();
    $scenario($ledger);
    expect(Transaction::withTrashed()->count())->toBeGreaterThan($before, 'Skenario tidak menulis apa pun');

    assertReconcileClean();
})->with([
    'create expense' => fn (LedgerClient $l) => $l->create('expense', 0, 25_000),
    'create income' => fn (LedgerClient $l) => $l->create('income', 0, 8_500_000),
    'update amount' => fn (LedgerClient $l) => $l->update($l->create('expense', 0, 25_000), ['amount' => 99_000]),
    'update wallet' => fn (LedgerClient $l) => $l->update($l->create('expense', 0, 25_000), ['wallet' => 1]),
    'update type' => fn (LedgerClient $l) => $l->update($l->create('expense', 0, 25_000), ['type' => 'income']),
    'update everything at once' => fn (LedgerClient $l) => $l->update($l->create('expense', 0, 25_000), ['wallet' => 3, 'type' => 'income', 'amount' => 1, 'occurred_on' => '2026-01-01']),
    'delete' => fn (LedgerClient $l) => $l->delete($l->create('expense', 0, 25_000)),
    'transfer' => fn (LedgerClient $l) => $l->transfer(0, 1, 300_000),
    'edit transfer amount' => fn (LedgerClient $l) => $l->updateTransfer($l->transfer(0, 1, 300_000), ['amount' => 1_000]),
    'edit transfer ends' => fn (LedgerClient $l) => $l->updateTransfer($l->transfer(0, 1, 300_000), ['from' => 2, 'to' => 3]),
    'reverse transfer' => fn (LedgerClient $l) => $l->updateTransfer($l->transfer(0, 1, 300_000), ['from' => 1, 'to' => 0]),
    'delete transfer' => fn (LedgerClient $l) => $l->deleteTransfer($l->transfer(0, 1, 300_000)),
    'transfer into private wallet' => fn (LedgerClient $l) => $l->transfer(0, 4, 50_000),
    'adjust down' => fn (LedgerClient $l) => $l->adjust(0, 900_000),
    'adjust up then undo' => function (LedgerClient $l) {
        $l->adjust(1, 400_000);
        $l->delete((int) Transaction::where('type', 'adjustment')->latest('id')->value('id'));
    },
    'pending is ignored' => fn (LedgerClient $l) => Transaction::factory()->forWallet($l->wallets[0])->expense(777_000)->pending()->create(),
]);

it('stays consistent through 300 random operations (seeded)', function () {
    $seed = (int) (getenv('LEDGER_SEED') ?: random_int(1, PHP_INT_MAX));
    mt_srand($seed);
    $ledger = new LedgerClient;
    $transactions = [];
    $transfers = [];
    $wallets = count($ledger->wallets);

    $pick = fn (array $items) => $items[mt_rand(0, count($items) - 1)];
    $otherWallet = function (int $not) use ($wallets) {
        do {
            $w = mt_rand(0, $wallets - 1);
        } while ($w === $not);

        return $w;
    };

    for ($i = 1; $i <= 300; $i++) {
        $op = $pick(['create', 'create', 'create', 'update', 'delete', 'transfer', 'transfer', 'editTransfer', 'deleteTransfer', 'adjust']);

        match (true) {
            $op === 'create' || ($op === 'update' && $transactions === []) || ($op === 'delete' && $transactions === []) => $transactions[] = $ledger->create(
                $pick(['expense', 'expense', 'income']), mt_rand(0, $wallets - 1), mt_rand(1, 2_000_000), sprintf('2026-%02d-%02d', mt_rand(1, 12), mt_rand(1, 28)),
            ),
            $op === 'update' => $ledger->update($pick($transactions), $pick([
                ['amount' => mt_rand(1, 2_000_000)],
                ['wallet' => mt_rand(0, $wallets - 1)],
                ['type' => $pick(['income', 'expense'])],
                ['wallet' => mt_rand(0, $wallets - 1), 'amount' => mt_rand(1, 500_000), 'type' => $pick(['income', 'expense'])],
            ])),
            $op === 'delete' => (function () use (&$transactions, $ledger) {
                $index = mt_rand(0, count($transactions) - 1);
                $ledger->delete($transactions[$index]);
                array_splice($transactions, $index, 1);
            })(),
            $op === 'transfer' || ($transfers === [] && in_array($op, ['editTransfer', 'deleteTransfer'], true)) => (function () use (&$transfers, $ledger, $wallets, $otherWallet) {
                $from = mt_rand(0, $wallets - 1);
                $transfers[] = $ledger->transfer($from, $otherWallet($from), mt_rand(1, 1_000_000));
            })(),
            $op === 'editTransfer' => (function () use ($transfers, $ledger, $wallets, $otherWallet, $pick) {
                $from = mt_rand(0, $wallets - 1);
                $ledger->updateTransfer($pick($transfers), $pick([
                    ['amount' => mt_rand(1, 1_000_000)],
                    ['from' => $from, 'to' => $otherWallet($from)],
                    ['from' => $from, 'to' => $otherWallet($from), 'amount' => mt_rand(1, 1_000_000)],
                ]));
            })(),
            $op === 'deleteTransfer' => (function () use (&$transfers, $ledger) {
                $index = mt_rand(0, count($transfers) - 1);
                $ledger->deleteTransfer($transfers[$index]);
                array_splice($transfers, $index, 1);
            })(),
            $op === 'adjust' => $ledger->adjust(mt_rand(0, $wallets - 1), mt_rand(-500_000, 10_000_000)),
            default => throw new LogicException($op),
        };

        if ($i % 50 === 0) {
            artisan('wallets:reconcile --no-notify')->assertSuccessful();
        }
    }

    // Seed tercetak supaya kegagalan bisa diulang: LEDGER_SEED=<seed> php artisan test --filter=random
    fwrite(STDERR, "\n  ledger seed: {$seed}, transaksi aktif: ".Transaction::count()."\n");
    assertReconcileClean();
    assertLedgerBalanced();
})->group('slow');
