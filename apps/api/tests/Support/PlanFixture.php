<?php

namespace Tests\Support;

use App\Models\Account;
use App\Models\Category;
use App\Models\PlanItem;
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
