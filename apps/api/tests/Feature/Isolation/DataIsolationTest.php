<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;

/*
| Keputusan #5: satu user tidak bisa membaca atau mengubah data user lain di route mana pun (IDOR).
|
| Setiap kasus dijalankan dua kali oleh penyerang yang login:
|   1. dengan id milik korban (`foreign`), dan
|   2. dengan id yang tidak ada sama sekali (`missing`).
| Status keduanya harus SAMA (tidak ada oracle "data ini ada tapi bukan milikmu"), id korban tidak
| boleh muncul di response, dan data korban harus persis sama sebelum vs sesudah.
|
| Penjaga: setiap route /api di router wajib punya minimal satu kasus di sini, atau terdaftar di
| ISOLATION_EXEMPT dengan alasan. Route baru tanpa kasus → test gagal.
*/

/** Route yang tidak menyentuh data milik user tertentu (hanya diri sendiri / belum login). */
const ISOLATION_EXEMPT = [
    'POST api/auth/register' => 'membuat user baru; tidak menerima id data',
    'POST api/auth/login' => 'kredensial sendiri',
    'POST api/auth/logout' => 'sesi sendiri',
    'POST api/auth/token' => 'kredensial sendiri',
    'DELETE api/auth/token' => 'hanya token Bearer yang sedang dipakai (MobileTokenTest)',
    'GET api/auth/me' => 'selalu user yang login',
    'PATCH api/auth/me' => 'selalu user yang login; email/password dilarang (ProfileTest)',
    'PUT api/auth/password' => 'selalu user yang login (ProfileTest)',
];

final class IsolationContext
{
    public User $victim;

    public User $attacker;

    public Account $victimAccount;

    public Account $victimAccount2;

    public Category $victimCategory;

    public Transaction $victimTransaction;

    public string $victimTransferGroup;

    public string $victimClientId;

    public DatabaseNotification $victimNotification;

    public PlanItem $victimPlanItem;

    public int $myPlanItemId;

    public Account $myAccount;

    public Account $myAccount2;

    public Category $myCategory;

    public int $myTransactionId;

    public function __construct()
    {
        $this->victim = User::factory()->create(['name' => 'Korban']);
        $this->victimAccount = Account::factory()->forUser($this->victim)->opening(1_000_000)->create(['name' => 'BCA Korban']);
        $this->victimAccount2 = Account::factory()->forUser($this->victim)->cash()->opening(50_000)->create(['name' => 'Dompet Korban']);
        $this->victimCategory = Category::factory()->forUser($this->victim)->create(['name' => 'Rahasia Korban']);
        $this->victimClientId = (string) Str::uuid();

        // Data korban dibuat lewat API sungguhan supaya saldonya konsisten.
        actingAs($this->victim)->postJson('/api/transactions', [
            'account_id' => $this->victimAccount->id, 'category_id' => $this->victimCategory->id, 'type' => 'expense',
            'amount' => 25_000, 'occurred_on' => '2026-11-21', 'note' => 'catatan rahasia', 'client_id' => $this->victimClientId,
        ])->assertCreated();
        $this->victimTransaction = Transaction::ownedBy($this->victim)->where('client_id', $this->victimClientId)->sole();
        $this->victimTransferGroup = (string) actingAs($this->victim)->postJson('/api/transfers', [
            'from_account_id' => $this->victimAccount->id, 'to_account_id' => $this->victimAccount2->id,
            'amount' => 100_000, 'occurred_on' => '2026-11-21', 'client_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data.transfer_group_id');

        $this->victimNotification = DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(), 'type' => 'reconcile_mismatch',
            'notifiable_type' => $this->victim->getMorphClass(), 'notifiable_id' => $this->victim->id,
            'data' => ['mismatches' => [['name' => 'BCA Korban']], 'fixed' => false],
        ]);

        // Rencana korban: bulan ini (dibaca) dan bulan lalu (sumber "salin dari bulan lalu").
        $this->victimPlanItem = PlanItem::factory()->forUser($this->victim)->forMonth('2026-11')
            ->create(['name' => 'Cicilan Rahasia', 'category_id' => $this->victimCategory->id, 'account_id' => $this->victimAccount->id, 'due_on' => '2026-11-05']);
        actingAs($this->victim)->postJson("/api/plan-items/{$this->victimPlanItem->id}/check", [
            'client_id' => (string) Str::uuid(), 'occurred_on' => '2026-11-05',
        ])->assertCreated();
        PlanItem::factory()->forUser($this->victim)->forMonth('2026-10')
            ->create(['name' => 'Cicilan Rahasia', 'category_id' => $this->victimCategory->id]);

        $this->attacker = User::factory()->create(['name' => 'Penyerang']);
        $this->myAccount = Account::factory()->forUser($this->attacker)->opening(500_000)->create(['name' => 'Akun Saya']);
        $this->myAccount2 = Account::factory()->forUser($this->attacker)->opening(0)->create(['name' => 'Akun Saya 2']);
        $this->myCategory = Category::factory()->forUser($this->attacker)->create(['name' => 'Kategori Saya']);
        $this->myTransactionId = (int) actingAs($this->attacker)->postJson('/api/transactions', [
            'account_id' => $this->myAccount->id, 'type' => 'expense', 'amount' => 5_000,
            'occurred_on' => '2026-11-22', 'client_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');
        $this->myPlanItemId = PlanItem::factory()->forUser($this->attacker)->forMonth('2026-11')
            ->create(['name' => 'Item Saya', 'category_id' => $this->myCategory->id])->id;
    }

    /**
     * Semua data korban (termasuk yang soft-deleted), untuk dibandingkan sebelum vs sesudah serangan.
     *
     * @return array<string, mixed>
     */
    public function victimSnapshot(): array
    {
        $rows = fn (string $table, string $column = 'user_id') => DB::table($table)
            ->where($column, $this->victim->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return [
            'user' => (array) DB::table('users')->where('id', $this->victim->id)->first(),
            'accounts' => $rows('accounts'),
            'categories' => $rows('categories'),
            'transactions' => $rows('transactions'),
            'notifications' => $rows('notifications', 'notifiable_id'),
            'plan_items' => $rows('plan_items'),
        ];
    }

    /**
     * Id korban yang tidak boleh muncul di response mana pun.
     *
     * @return list<string>
     */
    public function leakMarkers(): array
    {
        return ['BCA Korban', 'Dompet Korban', 'Rahasia Korban', 'catatan rahasia', 'Cicilan Rahasia', $this->victimTransferGroup, $this->victimNotification->id];
    }
}

/**
 * Satu kasus serangan: route + request yang dibangun dari id korban atau id yang tidak ada.
 * `$id(...)` mengembalikan id korban (mode foreign) atau id fiktif (mode missing).
 */
final class IsolationCase
{
    /**
     * @param  Closure(IsolationContext, Closure(string): (int|string)): array{0: string, 1: string, 2?: array<string, mixed>}  $request
     */
    public function __construct(public string $route, public Closure $request) {}
}

/**
 * @return array<string, IsolationCase>
 */
function isolationCases(): array
{
    $tx = fn (IsolationContext $c, array $overrides = []) => array_merge([
        'account_id' => $c->myAccount->id, 'type' => 'expense', 'amount' => 1_000,
        'occurred_on' => '2026-11-22', 'client_id' => (string) Str::uuid(),
    ], $overrides);
    $planItem = fn (IsolationContext $c, array $overrides = []) => array_merge([
        'type' => 'expense', 'mode' => 'checklist', 'name' => 'Item baru', 'category_id' => $c->myCategory->id, 'planned_amount' => 10_000,
    ], $overrides);
    $transfer = fn (IsolationContext $c, array $overrides = []) => array_merge([
        'from_account_id' => $c->myAccount->id, 'to_account_id' => $c->myAccount2->id, 'amount' => 1_000,
        'occurred_on' => '2026-11-22', 'client_id' => (string) Str::uuid(),
    ], $overrides);

    $cases = [
        // ---------- Akun ----------
        'list accounts' => ['GET api/accounts', fn ($c, $id) => ['GET', '/api/accounts?include_archived=1']],
        'create account with the victim’s name' => ['POST api/accounts', fn ($c, $id) => ['POST', '/api/accounts', ['name' => 'BCA Korban', 'type' => 'bank']]],
        'show account' => ['GET api/accounts/{account}', fn ($c, $id) => ['GET', '/api/accounts/'.$id('account')]],
        'update account' => ['PUT api/accounts/{account}', fn ($c, $id) => ['PATCH', '/api/accounts/'.$id('account'), ['name' => 'Diretas', 'opening_balance' => 0]]],
        'delete account' => ['DELETE api/accounts/{account}', fn ($c, $id) => ['DELETE', '/api/accounts/'.$id('account')]],
        'adjust account' => ['POST api/accounts/{account}/adjust', fn ($c, $id) => ['POST', '/api/accounts/'.$id('account').'/adjust', ['actual_balance' => 0, 'occurred_on' => '2026-11-22']]],
        'archive account' => ['POST api/accounts/{account}/archive', fn ($c, $id) => ['POST', '/api/accounts/'.$id('account').'/archive']],
        'unarchive account' => ['POST api/accounts/{account}/unarchive', fn ($c, $id) => ['POST', '/api/accounts/'.$id('account').'/unarchive']],

        // ---------- Kategori ----------
        'list categories' => ['GET api/categories', fn ($c, $id) => ['GET', '/api/categories?include_archived=1']],
        'create category with the victim’s name' => ['POST api/categories', fn ($c, $id) => ['POST', '/api/categories', ['kind' => 'expense', 'name' => 'Rahasia Korban']]],
        'show category' => ['GET api/categories/{category}', fn ($c, $id) => ['GET', '/api/categories/'.$id('category')]],
        'update category' => ['PUT api/categories/{category}', fn ($c, $id) => ['PATCH', '/api/categories/'.$id('category'), ['name' => 'Diretas']]],
        'delete category' => ['DELETE api/categories/{category}', fn ($c, $id) => ['DELETE', '/api/categories/'.$id('category')]],
        'archive category' => ['POST api/categories/{category}/archive', fn ($c, $id) => ['POST', '/api/categories/'.$id('category').'/archive']],
        'unarchive category' => ['POST api/categories/{category}/unarchive', fn ($c, $id) => ['POST', '/api/categories/'.$id('category').'/unarchive']],

        // ---------- Transaksi ----------
        'list transactions' => ['GET api/transactions', fn ($c, $id) => ['GET', '/api/transactions']],
        'list transactions filtered by the victim’s account' => ['GET api/transactions', fn ($c, $id) => ['GET', '/api/transactions?account_id='.$id('account')]],
        'list transactions filtered by the victim’s category' => ['GET api/transactions', fn ($c, $id) => ['GET', '/api/transactions?category_id='.$id('category')]],
        'search the victim’s note' => ['GET api/transactions', fn ($c, $id) => ['GET', '/api/transactions?q=rahasia']],
        'create transaction in the victim’s account' => ['POST api/transactions', fn ($c, $id) => ['POST', '/api/transactions', $tx($c, ['account_id' => $id('account')])]],
        'create transaction with the victim’s category' => ['POST api/transactions', fn ($c, $id) => ['POST', '/api/transactions', $tx($c, ['category_id' => $id('category')])]],
        'replay the victim’s client_id' => ['POST api/transactions', fn ($c, $id) => ['POST', '/api/transactions', $tx($c, ['client_id' => $id('client_id')])]],
        'show transaction' => ['GET api/transactions/{transaction}', fn ($c, $id) => ['GET', '/api/transactions/'.$id('transaction')]],
        'update transaction' => ['PUT api/transactions/{transaction}', fn ($c, $id) => ['PATCH', '/api/transactions/'.$id('transaction'), ['amount' => 1]]],
        'move own transaction into the victim’s account' => ['PUT api/transactions/{transaction}', fn ($c, $id) => ['PATCH', '/api/transactions/'.$id('own_transaction'), ['account_id' => $id('account')]]],
        'delete transaction' => ['DELETE api/transactions/{transaction}', fn ($c, $id) => ['DELETE', '/api/transactions/'.$id('transaction')]],

        // ---------- Transfer ----------
        'transfer out of the victim’s account' => ['POST api/transfers', fn ($c, $id) => ['POST', '/api/transfers', $transfer($c, ['from_account_id' => $id('account')])]],
        'transfer into the victim’s account' => ['POST api/transfers', fn ($c, $id) => ['POST', '/api/transfers', $transfer($c, ['to_account_id' => $id('account')])]],
        'replay the victim’s client_id as a transfer' => ['POST api/transfers', fn ($c, $id) => ['POST', '/api/transfers', $transfer($c, ['client_id' => $id('client_id')])]],
        'show transfer' => ['GET api/transfers/{transfer}', fn ($c, $id) => ['GET', '/api/transfers/'.$id('transfer')]],
        'update transfer' => ['PATCH api/transfers/{transfer}', fn ($c, $id) => ['PATCH', '/api/transfers/'.$id('transfer'), ['amount' => 1]]],
        'delete transfer' => ['DELETE api/transfers/{transfer}', fn ($c, $id) => ['DELETE', '/api/transfers/'.$id('transfer')]],

        // ---------- Notifikasi ----------
        'list notifications' => ['GET api/notifications', fn ($c, $id) => ['GET', '/api/notifications']],
        'read the victim’s notification' => ['POST api/notifications/{notification}/read', fn ($c, $id) => ['POST', '/api/notifications/'.$id('notification').'/read']],
        'read all notifications' => ['POST api/notifications/read-all', fn ($c, $id) => ['POST', '/api/notifications/read-all']],

        // ---------- Rencana ----------
        'list the month the victim planned' => ['GET api/plan/{month}', fn ($c, $id) => ['GET', '/api/plan/2026-11']],
        'add plan item with the victim’s category' => ['POST api/plan/{month}/items', fn ($c, $id) => ['POST', '/api/plan/2026-11/items', $planItem($c, ['category_id' => $id('category')])]],
        'add plan item with the victim’s default account' => ['POST api/plan/{month}/items', fn ($c, $id) => ['POST', '/api/plan/2026-11/items', $planItem($c, ['account_id' => $id('account')])]],
        'copy from the month the victim planned' => ['POST api/plan/{month}/copy-from-previous', fn ($c, $id) => ['POST', '/api/plan/2026-11/copy-from-previous']],
        'show plan item' => ['GET api/plan-items/{planItem}', fn ($c, $id) => ['GET', '/api/plan-items/'.$id('plan_item')]],
        'update plan item' => ['PATCH api/plan-items/{planItem}', fn ($c, $id) => ['PATCH', '/api/plan-items/'.$id('plan_item'), ['planned_amount' => 1]]],
        'point own plan item at the victim’s category' => ['PATCH api/plan-items/{planItem}', fn ($c, $id) => ['PATCH', '/api/plan-items/'.$c->myPlanItemId, ['category_id' => $id('category')]]],
        'delete plan item' => ['DELETE api/plan-items/{planItem}', fn ($c, $id) => ['DELETE', '/api/plan-items/'.$id('plan_item')]],
        'skip plan item' => ['POST api/plan-items/{planItem}/skip', fn ($c, $id) => ['POST', '/api/plan-items/'.$id('plan_item').'/skip']],
        'unskip plan item' => ['POST api/plan-items/{planItem}/unskip', fn ($c, $id) => ['POST', '/api/plan-items/'.$id('plan_item').'/unskip']],
        'check the victim’s plan item' => ['POST api/plan-items/{planItem}/check', fn ($c, $id) => ['POST', '/api/plan-items/'.$id('plan_item').'/check', ['client_id' => (string) Str::uuid(), 'account_id' => $c->myAccount->id]]],
        'check own item into the victim’s account' => ['POST api/plan-items/{planItem}/check', fn ($c, $id) => ['POST', '/api/plan-items/'.$c->myPlanItemId.'/check', ['client_id' => (string) Str::uuid(), 'account_id' => $id('account')]]],
        'check own item replaying the victim’s client_id' => ['POST api/plan-items/{planItem}/check', fn ($c, $id) => ['POST', '/api/plan-items/'.$c->myPlanItemId.'/check', ['client_id' => $id('client_id'), 'account_id' => $c->myAccount->id]]],
        'uncheck the victim’s plan item' => ['POST api/plan-items/{planItem}/uncheck', fn ($c, $id) => ['POST', '/api/plan-items/'.$id('plan_item').'/uncheck']],
    ];

    return array_map(fn (array $case) => new IsolationCase($case[0], $case[1]), $cases);
}

/**
 * @return array{0: TestResponse<Response>, 1: string} [response, request mentah]
 */
function attack(IsolationContext $c, IsolationCase $case, string $mode): array
{
    $ownTransaction = $c->myTransactionId;

    $foreign = [
        'account' => $c->victimAccount->id,
        'category' => $c->victimCategory->id,
        'transaction' => $c->victimTransaction->id,
        'transfer' => $c->victimTransferGroup,
        'client_id' => $c->victimClientId,
        'notification' => $c->victimNotification->id,
        'plan_item' => $c->victimPlanItem->id,
        'own_transaction' => $ownTransaction,
    ];
    $missing = [
        'account' => 999_999_999,
        'category' => 999_999_999,
        'transaction' => 999_999_999,
        'transfer' => (string) Str::uuid(),
        'client_id' => (string) Str::uuid(),
        'notification' => (string) Str::uuid(),
        'plan_item' => 999_999_999,
        'own_transaction' => $ownTransaction,
    ];
    $ids = $mode === 'foreign' ? $foreign : $missing;

    [$method, $url, $payload] = ($case->request)($c, fn (string $key) => $ids[$key]) + [2 => []];

    app('auth')->forgetGuards();

    return [actingAs($c->attacker)->json($method, $url, $payload), $url.' '.json_encode($payload)];
}

it('treats another user’s data exactly like data that does not exist', function (IsolationCase $case) {
    $c = new IsolationContext;
    $before = $c->victimSnapshot();

    // Jalankan di savepoint supaya percobaan pertama (mis. create) tidak memengaruhi percobaan kedua.
    DB::beginTransaction();
    [$missing] = attack($c, $case, 'missing');
    DB::rollBack();

    [$foreign, $request] = attack($c, $case, 'foreign');

    expect($foreign->getStatusCode())->toBe($missing->getStatusCode(), sprintf(
        "Status beda untuk data korban vs data yang tidak ada (%s):\nforeign %d: %s\nmissing %d: %s",
        $case->route, $foreign->getStatusCode(), $foreign->getContent(), $missing->getStatusCode(), $missing->getContent(),
    ))->and($foreign->getStatusCode())->not->toBe(500, (string) $foreign->getContent());

    // Nilai yang dikirim penyerang sendiri (mis. membuat akun bernama "BCA Korban") boleh muncul kembali.
    foreach ($c->leakMarkers() as $marker) {
        if (! str_contains($request, (string) $marker)) {
            expect((string) $foreign->getContent())->not->toContain((string) $marker);
        }
    }

    expect($c->victimSnapshot())->toBe($before, 'Data korban berubah');
    assertLedgerBalanced();
})->with(fn () => array_map(fn (IsolationCase $case) => [$case], isolationCases()));

it('covers every /api route with an isolation case or an explicit exemption', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/'))
        ->map(fn (Route $r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->first().' '.$r->uri())
        ->values()->all();

    $covered = array_unique(array_map(fn (IsolationCase $c) => $c->route, isolationCases()));
    $handled = [...$covered, ...array_keys(ISOLATION_EXEMPT)];

    expect(array_values(array_diff($routes, $handled)))->toBe([], 'Route tanpa kasus isolasi; tambahkan ke isolationCases() atau ISOLATION_EXEMPT')
        ->and(array_values(array_diff($handled, $routes)))->toBe([], 'Kasus/pengecualian untuk route yang sudah tidak ada');
});

it('really exercises the victim’s data (sanity check of the fixture)', function () {
    $c = new IsolationContext;

    actingAs($c->victim)->getJson('/api/accounts/'.$c->victimAccount->id)->assertOk();
    actingAs($c->victim)->getJson('/api/transfers/'.$c->victimTransferGroup)->assertOk();
    actingAs($c->victim)->getJson('/api/transactions?q=catatan')->assertJsonCount(1, 'data');
    actingAs($c->victim)->getJson('/api/plan/2026-11')->assertJsonPath('data.items.0.status', 'done');
    actingAs($c->victim)->getJson('/api/notifications')->assertJsonCount(1, 'data');
    actingAs($c->victim)->getJson('/api/plan/2026-11')->assertJsonPath('data.items.0.name', 'Cicilan Rahasia');
    actingAs($c->victim)->getJson('/api/plan-items/'.$c->victimPlanItem->id)->assertOk();
});
