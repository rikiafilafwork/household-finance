<?php

use App\Enums\HouseholdRole;
use App\Models\Category;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;

/*
| #30, Keputusan #5 / ADR-005: wallet privat hanya terlihat pemiliknya, TERMASUK dari owner
| household. Diuji per endpoint dari dua sudut pandang pengintip: member lain dan owner household.
| Isi wallet privat diberi penanda unik (nama, catatan, nominal) supaya kebocoran lewat field
| mana pun ikut tertangkap, bukan hanya lewat id.
| (Laporan & export ditambahkan di Minggu 7.)
*/

const SECRET_WALLET = 'Tabungan Rahasia Alia';
const SECRET_NOTE = 'kado-kejutan-ulang-tahun';
const SECRET_AMOUNT = 4_321_987;

final class PrivacyWorld
{
    public function __construct(
        public Household $household,
        public User $owner,
        public User $alia,  // pemilik wallet privat (member)
        public User $budi,  // member lain
        public Wallet $shared,
        public Wallet $private,
        public Transaction $secretTx,
        public string $secretTransfer,
        public Transaction $sharedTx,
    ) {}

    public static function make(): self
    {
        $owner = User::factory()->create();
        $alia = User::factory()->create();
        $budi = User::factory()->create();
        $household = Household::factory()->withOwner($owner)->create();
        foreach ([$alia, $budi] as $member) {
            $household->members()->attach($member, ['role' => HouseholdRole::Member, 'joined_at' => now()]);
        }
        $food = Category::factory()->for($household)->create();
        $shared = Wallet::factory()->for($household)->opening(10_000_000)->create(['name' => 'BCA Bersama']);
        $base = "/api/households/{$household->id}";

        $private = Wallet::query()->findOrFail(actingAs($alia)->postJson("{$base}/wallets", [
            'name' => SECRET_WALLET, 'type' => 'bank', 'visibility' => 'private', 'opening_balance' => 50_000_000,
        ])->assertCreated()->json('data.id'));

        $secretTx = Transaction::query()->findOrFail(actingAs($alia)->postJson("{$base}/transactions", [
            'wallet_id' => $private->id, 'category_id' => $food->id, 'type' => 'expense',
            'amount' => SECRET_AMOUNT, 'occurred_on' => '2026-11-20', 'note' => SECRET_NOTE, 'client_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id'));

        // Transfer dari wallet shared ke wallet privat: baris keluarnya ada di wallet yang terlihat semua.
        $secretTransfer = actingAs($alia)->postJson("{$base}/transfers", [
            'from_wallet_id' => $shared->id, 'to_wallet_id' => $private->id, 'amount' => 1_234_567,
            'occurred_on' => '2026-11-21', 'note' => 'setor tabungan', 'client_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data.transfer_group_id');

        $sharedTx = Transaction::query()->findOrFail(actingAs($owner)->postJson("{$base}/transactions", [
            'wallet_id' => $shared->id, 'category_id' => $food->id, 'type' => 'expense',
            'amount' => 50_000, 'occurred_on' => '2026-11-19', 'note' => 'belanja kado bersama', 'client_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id'));

        return new self($household, $owner, $alia, $budi, $shared, $private->fresh() ?? $private, $secretTx, $secretTransfer, $sharedTx);
    }

    public function url(string $path = ''): string
    {
        return "/api/households/{$this->household->id}{$path}";
    }

    public function viewer(string $who): User
    {
        return match ($who) {
            'member lain' => $this->budi,
            'owner household' => $this->owner,
            default => throw new InvalidArgumentException($who),
        };
    }
}

dataset('pengintip', ['member lain', 'owner household']);

/**
 * Tidak ada jejak isi wallet privat di respons: nama, catatan & nominal transaksinya, maupun
 * id-nya sebagai wallet_id. Catatan: baris KELUAR transfer shared → privat memang terlihat
 * (ada di wallet shared; riwayat wallet bersama wajib menjelaskan saldonya), tapi tujuannya tidak.
 *
 * @param  TestResponse<Response>  $response
 */
function assertNoSecrets(TestResponse $response, PrivacyWorld $w): void
{
    $response->assertDontSee(SECRET_WALLET)
        ->assertDontSee(SECRET_NOTE)
        ->assertDontSee((string) SECRET_AMOUNT)
        ->assertDontSee(ProbePrivacy::privateWalletRef($w));
}

final class ProbePrivacy
{
    /** Id wallet privat sebagai nilai field JSON (`"wallet_id":18`), bukan angka sembarang. */
    public static function privateWalletRef(PrivacyWorld $w): string
    {
        return '"wallet_id":'.$w->private->id.',';
    }
}

describe('daftar wallet', function () {
    it('hides the private wallet from the wallet list (also with archived)', function (string $who) {
        $w = PrivacyWorld::make();

        foreach (['/wallets', '/wallets?include_archived=1'] as $path) {
            $response = actingAs($w->viewer($who))->getJson($w->url($path))->assertOk();
            expect(array_column($response->json('data'), 'id'))->toBe([$w->shared->id]);
            assertNoSecrets($response, $w);
        }
    })->with('pengintip');

    it('forbids the private wallet detail and every change to it', function (string $who) {
        $w = PrivacyWorld::make();
        $viewer = $w->viewer($who);
        $path = "/wallets/{$w->private->id}";

        assertNoSecrets(actingAs($viewer)->getJson($w->url($path))->assertForbidden(), $w);
        actingAs($viewer)->patchJson($w->url($path), ['name' => 'X'])->assertForbidden();
        actingAs($viewer)->postJson($w->url("{$path}/archive"))->assertForbidden();
        actingAs($viewer)->postJson($w->url("{$path}/adjust"), ['actual_balance' => 0, 'occurred_on' => '2026-11-21'])->assertForbidden();
        actingAs($viewer)->deleteJson($w->url($path))->assertForbidden();

        $fresh = $w->private->fresh();
        expect($fresh?->name)->toBe(SECRET_WALLET)->and($fresh?->archived_at)->toBeNull();
    })->with('pengintip');
});

describe('daftar transaksi dan pencarian', function () {
    it('hides private wallet transactions from the transaction list', function (string $who) {
        $w = PrivacyWorld::make();

        $response = actingAs($w->viewer($who))->getJson($w->url('/transactions'))->assertOk();

        // Yang terlihat: transaksi shared + baris KELUAR transfer (di wallet shared). Baris masuk tidak.
        expect(array_column($response->json('data'), 'wallet_id'))->each->toBe($w->shared->id)
            ->and(array_column($response->json('data'), 'id'))->not->toContain($w->secretTx->id);
        assertNoSecrets($response, $w);
    })->with('pengintip');

    it('shows the outgoing leg of a transfer into the private wallet, but never its destination', function (string $who) {
        $w = PrivacyWorld::make();

        /** @var list<array{wallet_id: int, amount: int, transfer_group_id: string}> $rows */
        $rows = actingAs($w->viewer($who))->getJson($w->url('/transactions?type=transfer'))->assertOk()->json('data');
        $legs = collect($rows);

        // Saldo BCA Bersama turun Rp1.234.567: riwayat wallet shared harus bisa menjelaskannya.
        expect($legs)->toHaveCount(1)
            ->and($legs->first()['wallet_id'])->toBe($w->shared->id)
            ->and($legs->first()['amount'])->toBe(-1_234_567);
        // Pasangannya (wallet tujuan = privat) tidak bisa dibuka.
        actingAs($w->viewer($who))->getJson($w->url("/transfers/{$legs->first()['transfer_group_id']}"))->assertForbidden();
    })->with('pengintip');

    it('returns nothing when filtering by the private wallet id', function (string $who) {
        $w = PrivacyWorld::make();

        actingAs($w->viewer($who))->getJson($w->url("/transactions?wallet_id={$w->private->id}"))
            ->assertOk()->assertJsonCount(0, 'data');
    })->with('pengintip');

    it('does not find private transactions by search', function (string $who) {
        $w = PrivacyWorld::make();

        foreach (['kado-kejutan', 'kado', 'ulang-tahun'] as $term) {
            $response = actingAs($w->viewer($who))->getJson($w->url('/transactions?q='.urlencode($term)))->assertOk();
            expect(array_column($response->json('data'), 'id'))->not->toContain($w->secretTx->id);
            assertNoSecrets($response, $w);
        }
        // Kontrol: pencarian memang bekerja untuk data shared.
        actingAs($w->viewer($who))->getJson($w->url('/transactions?q=kado'))->assertJsonPath('data.0.id', $w->sharedTx->id);
    })->with('pengintip');

    it('does not leak through other filters (category, type, date range)', function (string $who) {
        $w = PrivacyWorld::make();

        foreach (["category_id={$w->secretTx->category_id}", 'type=expense', 'from=2026-11-20&to=2026-11-20', 'status=posted'] as $filter) {
            $response = actingAs($w->viewer($who))->getJson($w->url("/transactions?{$filter}"))->assertOk();
            expect(array_column($response->json('data'), 'id'))->not->toContain($w->secretTx->id);
        }
    })->with('pengintip');
});

describe('detail transaksi', function () {
    it('forbids the private transaction detail with 403', function (string $who) {
        $w = PrivacyWorld::make();

        assertNoSecrets(actingAs($w->viewer($who))->getJson($w->url("/transactions/{$w->secretTx->id}"))->assertForbidden(), $w);
    })->with('pengintip');

    it('forbids editing or deleting the private transaction', function (string $who) {
        $w = PrivacyWorld::make();
        $path = $w->url("/transactions/{$w->secretTx->id}");

        actingAs($w->viewer($who))->patchJson($path, ['amount' => 1])->assertForbidden();
        actingAs($w->viewer($who))->deleteJson($path)->assertForbidden();
        expect($w->secretTx->fresh()?->amount)->toBe(-SECRET_AMOUNT);
    })->with('pengintip');

    it('forbids a transfer that touches the private wallet, even though one leg is shared', function (string $who) {
        $w = PrivacyWorld::make();
        $path = $w->url("/transfers/{$w->secretTransfer}");

        assertNoSecrets(actingAs($w->viewer($who))->getJson($path)->assertForbidden(), $w);
        actingAs($w->viewer($who))->patchJson($path, ['amount' => 1])->assertForbidden();
        actingAs($w->viewer($who))->deleteJson($path)->assertForbidden();
    })->with('pengintip');

    it('forbids recording into someone else\'s private wallet', function (string $who) {
        $w = PrivacyWorld::make();

        actingAs($w->viewer($who))->postJson($w->url('/transactions'), [
            'wallet_id' => $w->private->id, 'type' => 'income', 'amount' => 1, 'occurred_on' => '2026-11-21', 'client_id' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['wallet_id' => 'tidak ditemukan']);
    })->with('pengintip');
});

describe('budget', function () {
    it('does not count private wallet spending in the shared budget', function (string $who) {
        $w = PrivacyWorld::make();
        actingAs($w->owner)->putJson($w->url('/budgets/2026-11'), ['items' => [
            ['category_id' => $w->secretTx->category_id, 'limit_amount' => 1_000_000],
        ]])->assertOk();

        $response = actingAs($w->viewer($who))->getJson($w->url('/budgets/2026-11'))->assertOk()
            ->assertJsonPath('data.items.0.spent', 50_000);
        assertNoSecrets($response, $w);
    })->with('pengintip');
});

describe('pemilik wallet privat', function () {
    it('sees everything of their own private wallet', function () {
        $w = PrivacyWorld::make();

        actingAs($w->alia)->getJson($w->url('/wallets'))->assertJsonFragment(['name' => SECRET_WALLET, 'is_mine' => true]);
        actingAs($w->alia)->getJson($w->url("/transactions/{$w->secretTx->id}"))->assertOk()->assertJsonPath('data.note', SECRET_NOTE);
        expect(array_column(actingAs($w->alia)->getJson($w->url('/transactions?q=kado-kejutan'))->json('data'), 'id'))
            ->toContain($w->secretTx->id)
            ->toHaveCount(1);
        actingAs($w->alia)->getJson($w->url("/transfers/{$w->secretTransfer}"))->assertOk();
    });

    it('loses access to their private wallet after leaving the household', function () {
        $w = PrivacyWorld::make();
        actingAs($w->owner)->deleteJson($w->url("/members/{$w->alia->id}"))->assertNoContent();

        actingAs($w->alia)->getJson($w->url('/wallets'))->assertForbidden();
        actingAs($w->alia)->getJson($w->url("/transactions/{$w->secretTx->id}"))->assertForbidden();
        // Dan owner tetap tidak bisa melihatnya walau pemiliknya sudah keluar.
        actingAs($w->owner)->getJson($w->url("/wallets/{$w->private->id}"))->assertForbidden();
        expect(array_column(actingAs($w->owner)->getJson($w->url('/wallets'))->json('data'), 'id'))->not->toContain($w->private->id);
    });
});
