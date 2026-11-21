<?php

use App\Enums\HouseholdRole;
use App\Models\Category;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;

/*
| #26: setiap baris tabel peran Keputusan #5 tertutup test, untuk owner DAN member.
| Satu dunia baru per kasus (aksi bersifat mengubah data). Kolom "Owner"/"Member" di bawah
| ditulis persis seperti di Architecture.md supaya mudah dicocokkan baris per baris.
|
| | Aksi                                               | Owner | Member              |
| | Undang / keluarkan anggota, ubah peran             | ✅    | ❌                  |
| | Buat / arsip wallet shared                         | ✅    | ❌                  |
| | Buat wallet private milik sendiri                  | ✅    | ✅                  |
| | Catat transaksi di wallet shared                   | ✅    | ✅                  |
| | Edit / hapus transaksi orang lain di wallet shared | ✅    | ❌ (hanya miliknya) |
| | Kelola kategori, budget, transaksi berulang shared | ✅    | ❌ (lihat saja)     |
| | Lihat wallet private orang lain                    | ❌    | ❌                  |
*/

beforeEach(fn () => Notification::fake());

final class RoleWorld
{
    public function __construct(
        public Household $household,
        public User $owner,
        public User $member,
        public User $extra,          // anggota ketiga, sasaran "keluarkan / ubah peran"
        public Wallet $shared,
        public Wallet $shared2,
        public Wallet $ownerPrivate,
        public Wallet $memberPrivate,
        public Category $category,
        public HouseholdInvitation $invitation,
        public Transaction $ownerTx,  // dicatat owner di wallet shared
        public Transaction $memberTx, // dicatat member di wallet shared
    ) {}

    public static function make(): self
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $extra = User::factory()->create();
        $household = Household::factory()->withOwner($owner)->create();
        foreach ([$member, $extra] as $user) {
            $household->members()->attach($user, ['role' => HouseholdRole::Member, 'joined_at' => now()]);
        }
        $shared = Wallet::factory()->for($household)->opening(5_000_000)->create();
        $category = Category::factory()->for($household)->create();

        $w = new self(
            $household, $owner, $member, $extra, $shared,
            Wallet::factory()->for($household)->cash()->opening(500_000)->create(),
            Wallet::factory()->for($household)->privateTo($owner)->opening(1_000_000)->create(),
            Wallet::factory()->for($household)->privateTo($member)->opening(1_000_000)->create(),
            $category,
            HouseholdInvitation::factory()->for($household)->create(['invited_by' => $owner->id]),
            new Transaction, new Transaction,
        );
        $w->ownerTx = Transaction::query()->findOrFail($w->record($owner, $shared)->assertCreated()->json('data.id'));
        $w->memberTx = Transaction::query()->findOrFail($w->record($member, $shared)->assertCreated()->json('data.id'));

        return $w;
    }

    public function url(string $path = ''): string
    {
        return "/api/households/{$this->household->id}{$path}";
    }

    /** @return TestResponse<Response> */
    public function record(User $actor, Wallet $wallet): TestResponse
    {
        return actingAs($actor)->postJson($this->url('/transactions'), [
            'wallet_id' => $wallet->id, 'category_id' => $this->category->id, 'type' => 'expense',
            'amount' => 10_000, 'occurred_on' => '2026-11-21', 'client_id' => (string) Str::uuid(),
        ]);
    }

    /** Anggota lain dari sudut pandang $actor (owner ↔ member). */
    public function counterpart(User $actor): User
    {
        return $actor->is($this->owner) ? $this->member : $this->owner;
    }
}

/**
 * @param  Closure(RoleWorld, User): TestResponse<Response>  $action
 */
function checkRole(Closure $action, string $role, bool $allowed): void
{
    $w = RoleWorld::make();
    $actor = $role === 'owner' ? $w->owner : $w->member;

    $response = $action($w, $actor);

    if ($allowed) {
        expect($response->status())->toBeGreaterThanOrEqual(200)->toBeLessThan(300, $response->content());
    } else {
        $response->assertForbidden();
    }
    assertLedgerBalanced();
}

/*
| Dataset: [aksi, owner boleh?, member boleh?]
*/
dataset('matriks', [
    // Undang / keluarkan anggota, ubah peran: ✅ / ❌
    'undang anggota' => [fn (RoleWorld $w, User $a) => actingAs($a)->postJson($w->url('/invitations'), ['email' => 'baru@example.com']), true, false],
    'cabut undangan' => [fn (RoleWorld $w, User $a) => actingAs($a)->deleteJson($w->url("/invitations/{$w->invitation->id}")), true, false],
    'lihat undangan tertunda' => [fn (RoleWorld $w, User $a) => actingAs($a)->getJson($w->url('/invitations')), true, false],
    'keluarkan anggota' => [fn (RoleWorld $w, User $a) => actingAs($a)->deleteJson($w->url("/members/{$w->extra->id}")), true, false],
    'ubah peran' => [fn (RoleWorld $w, User $a) => actingAs($a)->patchJson($w->url("/members/{$w->extra->id}"), ['role' => 'owner']), true, false],

    // Buat / arsip wallet shared: ✅ / ❌
    'buat wallet shared' => [fn (RoleWorld $w, User $a) => actingAs($a)->postJson($w->url('/wallets'), ['name' => 'Baru', 'type' => 'bank']), true, false],
    'arsip wallet shared' => [fn (RoleWorld $w, User $a) => actingAs($a)->postJson($w->url("/wallets/{$w->shared->id}/archive")), true, false],
    'ubah wallet shared' => [fn (RoleWorld $w, User $a) => actingAs($a)->patchJson($w->url("/wallets/{$w->shared->id}"), ['name' => 'Ganti']), true, false],
    'sesuaikan saldo wallet shared' => [fn (RoleWorld $w, User $a) => actingAs($a)->postJson($w->url("/wallets/{$w->shared->id}/adjust"), ['actual_balance' => 1, 'occurred_on' => '2026-11-21']), true, false],

    // Buat wallet private milik sendiri: ✅ / ✅ (dan mengelolanya)
    'buat wallet private sendiri' => [fn (RoleWorld $w, User $a) => actingAs($a)->postJson($w->url('/wallets'), ['name' => 'Saya', 'type' => 'cash', 'visibility' => 'private']), true, true],
    'arsip wallet private sendiri' => [fn (RoleWorld $w, User $a) => actingAs($a)->postJson($w->url('/wallets/'.($a->is($w->owner) ? $w->ownerPrivate : $w->memberPrivate)->id.'/archive')), true, true],

    // Catat transaksi di wallet shared: ✅ / ✅
    'catat transaksi di wallet shared' => [fn (RoleWorld $w, User $a) => $w->record($a, $w->shared), true, true],
    'transfer antar wallet shared' => [fn (RoleWorld $w, User $a) => actingAs($a)->postJson($w->url('/transfers'), [
        'from_wallet_id' => $w->shared->id, 'to_wallet_id' => $w->shared2->id, 'amount' => 1_000, 'occurred_on' => '2026-11-21', 'client_id' => (string) Str::uuid(),
    ]), true, true],

    // Edit / hapus transaksi orang lain di wallet shared: ✅ / ❌ (hanya miliknya)
    'edit transaksi orang lain' => [fn (RoleWorld $w, User $a) => actingAs($a)->patchJson($w->url('/transactions/'.($a->is($w->owner) ? $w->memberTx : $w->ownerTx)->id), ['amount' => 1]), true, false],
    'hapus transaksi orang lain' => [fn (RoleWorld $w, User $a) => actingAs($a)->deleteJson($w->url('/transactions/'.($a->is($w->owner) ? $w->memberTx : $w->ownerTx)->id)), true, false],
    'edit transaksi sendiri' => [fn (RoleWorld $w, User $a) => actingAs($a)->patchJson($w->url('/transactions/'.($a->is($w->owner) ? $w->ownerTx : $w->memberTx)->id), ['amount' => 1]), true, true],
    'hapus transaksi sendiri' => [fn (RoleWorld $w, User $a) => actingAs($a)->deleteJson($w->url('/transactions/'.($a->is($w->owner) ? $w->ownerTx : $w->memberTx)->id)), true, true],

    // Kelola kategori, budget, transaksi berulang shared: ✅ / ❌ (lihat saja)
    'buat kategori' => [fn (RoleWorld $w, User $a) => actingAs($a)->postJson($w->url('/categories'), ['kind' => 'expense', 'name' => 'Baru']), true, false],
    'ubah kategori' => [fn (RoleWorld $w, User $a) => actingAs($a)->patchJson($w->url("/categories/{$w->category->id}"), ['name' => 'Ganti']), true, false],
    'arsip kategori' => [fn (RoleWorld $w, User $a) => actingAs($a)->postJson($w->url("/categories/{$w->category->id}/archive")), true, false],
    'hapus kategori' => [fn (RoleWorld $w, User $a) => actingAs($a)->deleteJson($w->url('/categories/'.Category::factory()->for($w->household)->create()->id)), true, false],
    'set budget' => [fn (RoleWorld $w, User $a) => actingAs($a)->putJson($w->url('/budgets/2026-11'), ['items' => [['category_id' => $w->category->id, 'limit_amount' => 1_000_000]]]), true, false],
    'salin budget bulan lalu' => [fn (RoleWorld $w, User $a) => actingAs($a)->postJson($w->url('/budgets/2026-11/copy-from-previous')), true, false],
    'lihat kategori' => [fn (RoleWorld $w, User $a) => actingAs($a)->getJson($w->url('/categories')), true, true],
    'lihat budget' => [fn (RoleWorld $w, User $a) => actingAs($a)->getJson($w->url('/budgets/2026-11')), true, true],

    // Lihat wallet private orang lain: ❌ / ❌
    'lihat wallet private orang lain' => [fn (RoleWorld $w, User $a) => actingAs($a)->getJson($w->url('/wallets/'.($a->is($w->owner) ? $w->memberPrivate : $w->ownerPrivate)->id)), false, false],
    'kelola wallet private orang lain' => [fn (RoleWorld $w, User $a) => actingAs($a)->patchJson($w->url('/wallets/'.($a->is($w->owner) ? $w->memberPrivate : $w->ownerPrivate)->id), ['name' => 'X']), false, false],
]);

it('lets the owner do what the matrix allows', function (Closure $action, bool $owner, bool $member) {
    checkRole($action, 'owner', $owner);
})->with('matriks');

it('lets a member do only what the matrix allows', function (Closure $action, bool $owner, bool $member) {
    checkRole($action, 'member', $member);
})->with('matriks');

it('applies the matrix to recurring rules for shared wallets', function () {
    // Transaksi berulang dibuat di Minggu 5 (#33+): RecurringRulePolicy ikut baris "Kelola ... shared".
})->todo();

it('rejects outsiders on every household endpoint', function () {
    $w = RoleWorld::make();
    $outsider = User::factory()->create();

    foreach (['/', '/members', '/wallets', '/transactions', '/categories', '/budgets/2026-11', '/invitations', "/transactions/{$w->ownerTx->id}", "/wallets/{$w->shared->id}"] as $path) {
        actingAs($outsider)->getJson($w->url($path))->assertForbidden();
    }
});
