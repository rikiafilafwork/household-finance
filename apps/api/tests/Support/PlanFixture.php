<?php

namespace Tests\Support;

use App\Models\Account;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\Transaction;
use App\Models\User;
use App\Plan\PlanMonth;
use App\Queries\PlanComparison;
use App\Queries\PlanComparisonQuery;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/** Pembangun skenario rencana lewat API (dipakai test Plan). */
final class PlanFixture
{
    public User $user;

    public Account $bca;

    /** @var array<string, Category> */
    public array $cat = [];

    /** @var array<string, PlanItem> */
    public array $item = [];

    public function __construct()
    {
        $this->user = User::factory()->create();
        $this->bca = Account::factory()->forUser($this->user)->opening(20_000_000)->create(['name' => 'BCA']);
        foreach (['Cicilan', 'Tagihan', 'Makan', 'Transport', 'Hiburan', 'Kesehatan', 'Asuransi'] as $name) {
            $this->cat[$name] = Category::factory()->forUser($this->user)->create(['name' => $name]);
        }
        foreach (['Gaji', 'Freelance'] as $name) {
            $this->cat[$name] = Category::factory()->forUser($this->user)->income()->create(['name' => $name]);
        }
    }

    /**
     * Fixture angka Architecture.md #11 (dipakai PlanComparisonTest dan test dashboard):
     * pengeluaran rencana 5.100.000, aktual 4.075.000 (di luar rencana 180.000), sisa 1.025.000,
     * belum dibayar 500.000 (listrik); pemasukan rencana 9.500.000, aktual 8.000.000. Bulan 2026-11.
     */
    public static function architecture(): self
    {
        $f = new self;
        $f->checklist('Cicilan motor', 'Cicilan', 1_200_000);
        $f->checklist('Internet', 'Tagihan', 350_000);
        $f->checklist('Pulsa', 'Tagihan', 150_000);
        $f->checklist('Listrik', 'Tagihan', 500_000);
        $f->envelope('Makan', 2_000_000);
        $f->envelope('Transport', 600_000);
        $f->envelope('Hiburan', 300_000);
        $f->checklist('Gaji', 'Gaji', 8_000_000, 'income');
        $f->envelope('Freelance', 1_500_000, 'income');
        $f->checklist('Asuransi tahunan', 'Asuransi', 1_000_000);
        actingAs($f->user)->postJson("/api/plan-items/{$f->item['Asuransi tahunan']->id}/skip")->assertOk();

        $f->check('Cicilan motor', 1_200_000, '2026-11-05');
        $f->check('Internet', 350_000);
        $f->check('Pulsa', 165_000); // aktual beda dari rencana
        $f->check('Gaji', 8_000_000, '2026-11-25');
        foreach ([450_000, 450_000, 450_000] as $amount) {
            $f->spend('Makan', $amount);
        }
        $f->spend('Transport', 510_000);
        $f->spend('Hiburan', 320_000);
        $f->spend('Kesehatan', 180_000); // di luar rencana

        // Derau yang TIDAK boleh ikut dihitung:
        $f->spend('Makan', 99_000, '2026-10-31');                                    // bulan lain
        actingAs($f->user)->deleteJson('/api/transactions/'.$f->spend('Makan', 77_000))->assertNoContent(); // dihapus
        $cash = Account::factory()->forUser($f->user)->cash()->create();
        actingAs($f->user)->postJson('/api/transfers', [
            'from_account_id' => $f->bca->id, 'to_account_id' => $cash->id, 'amount' => 300_000, 'occurred_on' => '2026-11-12', 'client_id' => (string) Str::uuid(),
        ])->assertCreated();                                                          // transfer
        actingAs($f->user)->postJson("/api/accounts/{$cash->id}/adjust", ['actual_balance' => 250_000, 'occurred_on' => '2026-11-20'])->assertCreated(); // adjustment
        $other = Account::factory()->create();                                       // user lain
        Transaction::factory()->forAccount($other)->expense(5_000_000)->create(['occurred_on' => '2026-11-15']);

        return $f;
    }

    public function checklist(string $name, string $category, int $planned, string $type = 'expense'): PlanItem
    {
        return $this->item[$name] = PlanItem::factory()->forUser($this->user)->create([
            'name' => $name, 'type' => $type, 'category_id' => $this->cat[$category]->id, 'planned_amount' => $planned, 'account_id' => $this->bca->id,
        ]);
    }

    public function envelope(string $category, int $planned, string $type = 'expense'): PlanItem
    {
        return $this->item[$category] = PlanItem::factory()->forUser($this->user)->envelope()->create([
            'name' => $category, 'type' => $type, 'category_id' => $this->cat[$category]->id, 'planned_amount' => $planned,
        ]);
    }

    public function check(string $name, int $amount, string $date = '2026-11-10'): void
    {
        actingAs($this->user)->postJson("/api/plan-items/{$this->item[$name]->id}/check", [
            'client_id' => (string) Str::uuid(), 'amount' => $amount, 'occurred_on' => $date,
        ])->assertCreated();
    }

    public function spend(?string $category, int $amount, string $date = '2026-11-15', string $type = 'expense'): int
    {
        return (int) actingAs($this->user)->postJson('/api/transactions', [
            'account_id' => $this->bca->id, 'type' => $type, 'amount' => $amount, 'occurred_on' => $date,
            'category_id' => $category === null ? null : $this->cat[$category]->id, 'client_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');
    }

    public function comparison(string $month = '2026-11'): PlanComparison
    {
        return app(PlanComparisonQuery::class)->for($this->user, PlanMonth::parse($month));
    }
}
