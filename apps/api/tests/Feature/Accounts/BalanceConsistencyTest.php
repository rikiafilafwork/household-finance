<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

/*
| Keputusan #3 / ADR-002: setelah skenario tulis apa pun, `accounts:reconcile` (command sungguhan,
| bukan helper test) harus melaporkan selisih 0.
*/

/**
 * Klien API kecil untuk skenario saldo.
 */
final class LedgerClient
{
    public readonly User $owner;

    /** @var list<Account> */
    public array $accounts = [];

    public readonly Category $food;

    public readonly Category $salary;

    public function __construct()
    {
        $this->owner = User::factory()->create();

        foreach ([1_000_000, 250_000, 0, 5_000_000, 75_000] as $i => $opening) {
            $this->accounts[] = Account::factory()->forUser($this->owner)->opening($opening)->create(['name' => "A{$i}"]);
        }
        // Akun milik user lain ikut ada: reconcile mencakup semua akun, dan tidak boleh tersentuh.
        Account::factory()->opening(42_000)->create();

        $this->food = Category::factory()->forUser($this->owner)->create();
        $this->salary = Category::factory()->forUser($this->owner)->income()->create();
    }

    private function url(string $path): string
    {
        return "/api/{$path}";
    }

    public function create(string $type, int $accountIndex, int $amount, string $date = '2026-11-21'): int
    {
        return (int) actingAs($this->owner)->postJson($this->url('transactions'), [
            'account_id' => $this->accounts[$accountIndex]->id,
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
        if (isset($changes['account'])) {
            $changes['account_id'] = $this->accounts[$changes['account']]->id;
            unset($changes['account']);
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
            'from_account_id' => $this->accounts[$from]->id,
            'to_account_id' => $this->accounts[$to]->id,
            'amount' => $amount,
            'occurred_on' => '2026-11-21',
            'client_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data.transfer_group_id');
    }

    /**
     * @param  array<string, int>  $changes  from/to = indeks akun, amount
     */
    public function updateTransfer(string $group, array $changes): void
    {
        $payload = [];
        if (isset($changes['from'])) {
            $payload['from_account_id'] = $this->accounts[$changes['from']]->id;
        }
        if (isset($changes['to'])) {
            $payload['to_account_id'] = $this->accounts[$changes['to']]->id;
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

    public function adjust(int $accountIndex, int $actual): void
    {
        actingAs($this->owner)->postJson($this->url("accounts/{$this->accounts[$accountIndex]->id}/adjust"), [
            'actual_balance' => $actual, 'occurred_on' => '2026-11-21',
        ])->assertSuccessful();
    }

    public function balance(int $accountIndex): int
    {
        return (int) $this->accounts[$accountIndex]->fresh()?->balance;
    }
}

function assertReconcileClean(): void
{
    artisan('accounts:reconcile --no-notify')->expectsOutputToContain('Selisih 0')->assertSuccessful();
}

it('stays consistent in every named scenario', function (Closure $scenario) {
    $ledger = new LedgerClient;

    // Catatan: parameter bertipe Closure → Pest meneruskan closure apa adanya (tidak dieksekusi).
    // Pengaman supaya skenario tidak diam-diam kosong seperti versi pertama test ini.
    $before = Transaction::ownedBy($ledger->owner)->withTrashed()->count();
    $scenario($ledger);
    expect(Transaction::ownedBy($ledger->owner)->withTrashed()->count())->toBeGreaterThan($before, 'Skenario tidak menulis apa pun');

    assertReconcileClean();
})->with([
    'create expense' => fn (LedgerClient $l) => $l->create('expense', 0, 25_000),
    'create income' => fn (LedgerClient $l) => $l->create('income', 0, 8_500_000),
    'update amount' => fn (LedgerClient $l) => $l->update($l->create('expense', 0, 25_000), ['amount' => 99_000]),
    'update account' => fn (LedgerClient $l) => $l->update($l->create('expense', 0, 25_000), ['account' => 1]),
    'update type' => fn (LedgerClient $l) => $l->update($l->create('expense', 0, 25_000), ['type' => 'income']),
    'update everything at once' => fn (LedgerClient $l) => $l->update($l->create('expense', 0, 25_000), ['account' => 3, 'type' => 'income', 'amount' => 1, 'occurred_on' => '2026-01-01']),
    'delete' => fn (LedgerClient $l) => $l->delete($l->create('expense', 0, 25_000)),
    'transfer' => fn (LedgerClient $l) => $l->transfer(0, 1, 300_000),
    'edit transfer amount' => fn (LedgerClient $l) => $l->updateTransfer($l->transfer(0, 1, 300_000), ['amount' => 1_000]),
    'edit transfer ends' => fn (LedgerClient $l) => $l->updateTransfer($l->transfer(0, 1, 300_000), ['from' => 2, 'to' => 3]),
    'reverse transfer' => fn (LedgerClient $l) => $l->updateTransfer($l->transfer(0, 1, 300_000), ['from' => 1, 'to' => 0]),
    'delete transfer' => fn (LedgerClient $l) => $l->deleteTransfer($l->transfer(0, 1, 300_000)),
    'transfer into the fifth account' => fn (LedgerClient $l) => $l->transfer(0, 4, 50_000),
    'adjust down' => fn (LedgerClient $l) => $l->adjust(0, 900_000),
    'adjust up then undo' => function (LedgerClient $l) {
        $l->adjust(1, 400_000);
        $l->delete((int) Transaction::ownedBy($l->owner)->where('type', 'adjustment')->latest('id')->value('id'));
    },
]);

it('stays consistent through 300 random operations (seeded)', function () {
    $seed = (int) (getenv('LEDGER_SEED') ?: random_int(1, PHP_INT_MAX));
    mt_srand($seed);
    $ledger = new LedgerClient;
    $transactions = [];
    $transfers = [];
    $accounts = count($ledger->accounts);

    $pick = fn (array $items) => $items[mt_rand(0, count($items) - 1)];
    $otherAccount = function (int $not) use ($accounts) {
        do {
            $a = mt_rand(0, $accounts - 1);
        } while ($a === $not);

        return $a;
    };

    for ($i = 1; $i <= 300; $i++) {
        $op = $pick(['create', 'create', 'create', 'update', 'delete', 'transfer', 'transfer', 'editTransfer', 'deleteTransfer', 'adjust']);

        match (true) {
            $op === 'create' || ($op === 'update' && $transactions === []) || ($op === 'delete' && $transactions === []) => $transactions[] = $ledger->create(
                $pick(['expense', 'expense', 'income']), mt_rand(0, $accounts - 1), mt_rand(1, 2_000_000), sprintf('2026-%02d-%02d', mt_rand(1, 12), mt_rand(1, 28)),
            ),
            $op === 'update' => $ledger->update($pick($transactions), $pick([
                ['amount' => mt_rand(1, 2_000_000)],
                ['account' => mt_rand(0, $accounts - 1)],
                ['type' => $pick(['income', 'expense'])],
                ['account' => mt_rand(0, $accounts - 1), 'amount' => mt_rand(1, 500_000), 'type' => $pick(['income', 'expense'])],
            ])),
            $op === 'delete' => (function () use (&$transactions, $ledger) {
                $index = mt_rand(0, count($transactions) - 1);
                $ledger->delete($transactions[$index]);
                array_splice($transactions, $index, 1);
            })(),
            $op === 'transfer' || ($transfers === [] && in_array($op, ['editTransfer', 'deleteTransfer'], true)) => (function () use (&$transfers, $ledger, $accounts, $otherAccount) {
                $from = mt_rand(0, $accounts - 1);
                $transfers[] = $ledger->transfer($from, $otherAccount($from), mt_rand(1, 1_000_000));
            })(),
            $op === 'editTransfer' => (function () use ($transfers, $ledger, $accounts, $otherAccount, $pick) {
                $from = mt_rand(0, $accounts - 1);
                $ledger->updateTransfer($pick($transfers), $pick([
                    ['amount' => mt_rand(1, 1_000_000)],
                    ['from' => $from, 'to' => $otherAccount($from)],
                    ['from' => $from, 'to' => $otherAccount($from), 'amount' => mt_rand(1, 1_000_000)],
                ]));
            })(),
            $op === 'deleteTransfer' => (function () use (&$transfers, $ledger) {
                $index = mt_rand(0, count($transfers) - 1);
                $ledger->deleteTransfer($transfers[$index]);
                array_splice($transfers, $index, 1);
            })(),
            $op === 'adjust' => $ledger->adjust(mt_rand(0, $accounts - 1), mt_rand(-500_000, 10_000_000)),
            default => throw new LogicException($op),
        };

        if ($i % 50 === 0) {
            artisan('accounts:reconcile --no-notify')->assertSuccessful();
        }
    }

    // Seed tercetak supaya kegagalan bisa diulang: LEDGER_SEED=<seed> php artisan test --filter=random
    fwrite(STDERR, "\n  ledger seed: {$seed}, transaksi aktif: ".Transaction::ownedBy($ledger->owner)->count()."\n");
    assertReconcileClean();
    assertLedgerBalanced();
})->group('slow');
