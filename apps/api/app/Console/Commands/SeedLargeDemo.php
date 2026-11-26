<?php

namespace App\Console\Commands;

use App\Actions\Accounts\ReconcileAccounts;
use App\Enums\AccountType;
use App\Enums\CategoryKind;
use App\Models\Account;
use App\Models\Category;
use App\Models\Import;
use App\Models\PlanItem;
use App\Models\PlanTemplate;
use App\Models\Transaction;
use App\Models\User;
use App\Plan\PlanMonth;
use App\Support\DefaultCategories;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Data besar untuk bukti performa (Minggu 7, Keputusan #14):
 *
 *  - Akun demo `demo-large@example.test`: 8 akun, rencana N bulan (checklist dicentang dengan
 *    transaksi terhubung + amplop), transaksi harian, transfer, sesuaikan saldo; total ±200 ribu.
 *  - ±1 juta transaksi "noise" di 500 user lain pada bulan yang sama. Tanpa noise, index `user_id`
 *    tidak diuji dan angka benchmark menipu.
 *
 * Insert langsung per 5.000 baris (bukan Actions/factory satu per satu); saldo akun dihitung dari
 * jumlah yang dimasukkan, jadi `accounts:reconcile` tetap 0. Deterministik (--seed). Ditolak di
 * production. `--fresh` menghapus data seed-large sebelumnya (akun demo + user noise).
 */
class SeedLargeDemo extends Command
{
    public const DEMO_EMAIL = 'demo-large@example.test';

    public const NOISE_DOMAIN = 'noise.seed-large.test';

    private const BATCH = 5_000;

    protected $signature = 'demo:seed-large
        {--transactions=200000 : Transaksi akun demo}
        {--noise=1000000 : Transaksi noise di user lain}
        {--noise-users=500 : Jumlah user noise}
        {--months=36 : Rentang bulan, berakhir bulan berjalan}
        {--seed=2026 : Seed acak (hasil sama tiap run)}
        {--fresh : Hapus data seed-large sebelumnya dulu}';

    protected $description = 'Akun demo ±200 ribu transaksi + ±1 juta noise di 500 user lain (untuk benchmark laporan)';

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private int $inserted = 0;

    /** @var array<int, int> account_id → Σ amount */
    private array $sums = [];

    /** @var array<int, int> account_id → user_id */
    private array $owners = [];

    public function handle(ReconcileAccounts $reconcile): int
    {
        if (app()->isProduction()) {
            $this->error('demo:seed-large tidak boleh dijalankan di production.');

            return self::FAILURE;
        }

        $months = max(1, (int) $this->option('months'));
        $target = max(0, (int) $this->option('transactions'));
        $noise = max(0, (int) $this->option('noise'));
        $noiseUsers = max(1, (int) $this->option('noise-users'));
        mt_srand((int) $this->option('seed'));

        $exists = User::query()->where('email', self::DEMO_EMAIL)->exists();
        if ($exists && ! $this->option('fresh')) {
            $this->error('Data seed-large sudah ada. Pakai --fresh untuk membuat ulang.');

            return self::FAILURE;
        }
        if ($this->option('fresh')) {
            $this->purge();
        }

        $started = microtime(true);
        $end = PlanMonth::parse(CarbonImmutable::now('Asia/Jakarta')->format('Y-m'));
        $start = $end;
        for ($i = 1; $i < $months; $i++) {
            $start = $start->previous();
        }
        $range = PlanMonth::range($start, $end);
        $today = CarbonImmutable::now('Asia/Jakarta')->toDateString();

        $demo = $this->seedDemo($range, $target, $today);
        $this->info(sprintf('Akun demo: %s transaksi (%.1f dtk)', number_format($this->countFor($demo), 0, ',', '.'), microtime(true) - $started));

        $this->seedNoise($range, $noise, $noiseUsers, $today);
        $this->info(sprintf('Noise: %d user, %s transaksi (%.1f dtk total)', $noiseUsers, number_format($noise, 0, ',', '.'), microtime(true) - $started));

        $mismatches = $reconcile->mismatches($demo->id)->count();
        $this->line("Reconcile akun demo: {$mismatches} selisih.");
        $this->line('Login: '.self::DEMO_EMAIL.' — atur password dengan: php artisan user:reset-password '.self::DEMO_EMAIL);

        return $mismatches === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<PlanMonth>  $range
     */
    private function seedDemo(array $range, int $target, string $today): User
    {
        $user = User::query()->create([
            'name' => 'Demo Besar', 'email' => self::DEMO_EMAIL, 'password' => Hash::make(Str::random(32)), 'timezone' => 'Asia/Jakarta',
        ]);
        DefaultCategories::seedFor($user);
        $cat = Category::ownedBy($user)->get()->mapWithKeys(fn (Category $c) => [$c->kind->value.':'.$c->name => (int) $c->id])->all();

        $acc = [];
        foreach ([
            ['BCA', AccountType::Bank, 15_000_000], ['Mandiri', AccountType::Bank, 5_000_000], ['Bank Jago', AccountType::Bank, 2_000_000],
            ['SeaBank', AccountType::Bank, 1_000_000], ['GoPay', AccountType::Ewallet, 300_000], ['OVO', AccountType::Ewallet, 100_000],
            ['ShopeePay', AccountType::Ewallet, 50_000], ['Dompet', AccountType::Cash, 500_000],
        ] as $i => [$name, $type, $opening]) {
            $acc[$name] = (int) Account::ownedBy($user)->create([
                'user_id' => $user->id, 'name' => $name, 'type' => $type, 'opening_balance' => $opening, 'balance' => $opening, 'sort_order' => $i,
            ])->id;
        }
        $spendFrom = [[$acc['BCA'], 35], [$acc['GoPay'], 20], [$acc['Dompet'], 20], [$acc['OVO'], 8], [$acc['ShopeePay'], 7], [$acc['Mandiri'], 5], [$acc['Bank Jago'], 5]];
        $categories = [['Makan', 45, 15, 120], ['Transport', 20, 10, 80], ['Belanja', 12, 25, 400], ['Hiburan', 8, 25, 300],
            ['Kesehatan', 4, 30, 500], ['Pendidikan', 2, 50, 800], ['Lainnya', 9, 5, 200]];

        $checklists = [
            ['Gaji', 'income', 'Gaji', 12_000_000, 25, $acc['BCA']],
            ['Cicilan motor', 'expense', 'Tagihan', 1_200_000, 5, $acc['BCA']],
            ['Internet', 'expense', 'Tagihan', 350_000, 10, $acc['BCA']],
            ['Listrik', 'expense', 'Tagihan', 500_000, 15, $acc['Mandiri']],
            ['Pulsa', 'expense', 'Tagihan', 150_000, 3, $acc['GoPay']],
        ];
        $envelopes = [['Makan', 3_000_000], ['Transport', 1_000_000], ['Belanja', 1_500_000], ['Hiburan', 600_000], ['Kesehatan', 400_000]];

        // Template supaya plan:generate bulan depan juga terisi (tidak dipakai untuk item lama).
        foreach ($checklists as $i => [$name, $type, $category, $planned, $day, $account]) {
            PlanTemplate::ownedBy($user)->create([
                'user_id' => $user->id, 'type' => $type, 'mode' => 'checklist', 'name' => $name, 'category_id' => $cat["{$type}:{$category}"],
                'planned_amount' => $planned, 'account_id' => $account, 'day_of_month' => $day,
            ]);
        }

        $perMonth = intdiv($target, count($range));
        foreach ($range as $index => $month) {
            $budget = $index === count($range) - 1 ? $target - $perMonth * (count($range) - 1) : $perMonth;
            $lastDay = min($month->lastDay(), $today);
            $daysInMonth = (int) substr($lastDay, 8, 2);

            foreach ($envelopes as $j => [$category, $planned]) {
                PlanItem::ownedBy($user)->create([
                    'user_id' => $user->id, 'month' => $month->firstDay(), 'type' => 'expense', 'mode' => 'envelope', 'name' => $category,
                    'category_id' => $cat["expense:{$category}"], 'planned_amount' => $planned, 'sort_order' => 10 + $j, 'source' => 'template',
                ]);
            }
            foreach ($checklists as $j => [$name, $type, $category, $planned, $day, $account]) {
                $due = $month->day($day);
                $item = PlanItem::ownedBy($user)->create([
                    'user_id' => $user->id, 'month' => $month->firstDay(), 'type' => $type, 'mode' => 'checklist', 'name' => $name,
                    'category_id' => $cat["{$type}:{$category}"], 'planned_amount' => $planned, 'account_id' => $account, 'due_on' => $due,
                    'sort_order' => $j, 'source' => 'template',
                ]);
                if ($due <= $today && $budget > 0 && mt_rand(1, 100) <= 92) {
                    $amount = $name === 'Listrik' ? mt_rand(380, 620) * 1_000 : $planned;
                    $this->add($user->id, $account, $cat["{$type}:{$category}"], $type, $type === 'income' ? $amount : -$amount, $due, $name, planItemId: (int) $item->id);
                    $budget--;
                }
            }

            // Transfer: top up e-wallet dan tarik tunai, kira-kira tiap minggu.
            for ($w = 0; $w < 4 && $budget >= 2; $w++) {
                $date = sprintf('%s-%02d', $month->key(), min($daysInMonth, 1 + $w * 7 + mt_rand(0, 5)));
                $to = [$acc['GoPay'], $acc['OVO'], $acc['Dompet'], $acc['ShopeePay']][mt_rand(0, 3)];
                $amount = mt_rand(10, 60) * 10_000;
                $group = (string) Str::uuid();
                $this->add($user->id, $acc['BCA'], null, 'transfer', -$amount, $date, null, $group);
                $this->add($user->id, $to, null, 'transfer', $amount, $date, null, $group);
                $budget -= 2;
            }
            if ($budget > 0) {                                     // sesuaikan saldo dompet di akhir bulan
                $this->add($user->id, $acc['Dompet'], null, 'adjustment', -mt_rand(1, 30) * 1_000, $lastDay, 'Selisih dompet');
                $budget--;
            }
            if ($budget > 0 && mt_rand(1, 100) <= 60) {            // freelance (amplop pemasukan tanpa item)
                $this->add($user->id, $acc['Bank Jago'], $cat['income:Freelance'], 'income', mt_rand(5, 40) * 100_000, sprintf('%s-%02d', $month->key(), mt_rand(1, $daysInMonth)), 'Proyek freelance');
                $budget--;
            }

            for (; $budget > 0; $budget--) {                       // pengeluaran harian
                [$category, , $min, $max] = self::weighted($categories);
                $this->add(
                    $user->id, self::weighted($spendFrom)[0], $cat["expense:{$category}"], 'expense', -mt_rand($min, $max) * 1_000,
                    sprintf('%s-%02d', $month->key(), mt_rand(1, $daysInMonth)), mt_rand(1, 100) <= 30 ? $category.' '.mt_rand(1, 999) : null,
                );
            }
        }
        $this->flush();
        $this->applyBalances();

        return $user;
    }

    /**
     * @param  list<PlanMonth>  $range
     */
    private function seedNoise(array $range, int $total, int $users, string $today): void
    {
        $first = $range[0]->firstDay();
        $span = (int) CarbonImmutable::parse($first)->diffInDays(CarbonImmutable::parse($today)) + 1;
        $dates = [];                                                  // sekali, bukan 1 juta objek Carbon
        for ($d = CarbonImmutable::parse($first); $d->toDateString() <= $today; $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }
        $perUser = intdiv($total, $users);
        // User noise tidak pernah login: satu hash bcrypt untuk semua (500× Hash::make = puluhan detik).
        $password = Hash::make(Str::random(32));

        for ($u = 1; $u <= $users; $u++) {
            $user = User::query()->create([
                'name' => "Noise {$u}", 'email' => sprintf('noise-%04d@%s', $u, self::NOISE_DOMAIN),
                'password' => $password, 'timezone' => 'Asia/Jakarta',
            ]);
            DefaultCategories::seedFor($user);
            $expense = Category::ownedBy($user)->where('kind', CategoryKind::Expense->value)->pluck('id')->all();
            $income = (int) Category::ownedBy($user)->where('kind', CategoryKind::Income->value)->value('id');
            $accounts = [];
            foreach ([['Bank', AccountType::Bank], ['E-wallet', AccountType::Ewallet]] as [$name, $type]) {
                $accounts[] = (int) Account::ownedBy($user)->create([
                    'user_id' => $user->id, 'name' => $name, 'type' => $type, 'opening_balance' => 1_000_000, 'balance' => 1_000_000,
                ])->id;
            }

            $count = $u === $users ? $total - $perUser * ($users - 1) : $perUser;
            for ($i = 0; $i < $count; $i++) {
                $date = $dates[mt_rand(0, $span - 1)];
                if (mt_rand(1, 100) <= 8) {
                    $this->add($user->id, $accounts[0], $income, 'income', mt_rand(10, 200) * 100_000, $date, null);
                } else {
                    $this->add($user->id, $accounts[mt_rand(0, 1)], $expense[mt_rand(0, count($expense) - 1)], 'expense', -mt_rand(5, 500) * 1_000, $date, null);
                }
            }
            if ($u % 50 === 0) {
                $this->line("  noise: {$u}/{$users} user");
            }
        }
        $this->flush();
        $this->applyBalances();
    }

    private function add(int $userId, int $accountId, ?int $categoryId, string $type, int $amount, string $date, ?string $note, ?string $group = null, ?int $planItemId = null): void
    {
        $this->buffer[] = [
            'user_id' => $userId, 'account_id' => $accountId, 'category_id' => $categoryId, 'type' => $type, 'amount' => $amount,
            'occurred_on' => $date, 'note' => $note, 'transfer_group_id' => $group, 'plan_item_id' => $planItemId,
            'created_at' => $date.' 12:00:00', 'updated_at' => $date.' 12:00:00',
        ];
        $this->sums[$accountId] = ($this->sums[$accountId] ?? 0) + $amount;
        $this->owners[$accountId] = $userId;
        if (count($this->buffer) >= self::BATCH) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if ($this->buffer !== []) {
            Transaction::query()->insert($this->buffer);
            $this->inserted += count($this->buffer);
            $this->buffer = [];
        }
    }

    /** Saldo = saldo awal + Σ transaksi yang dimasukkan (sama dengan rumus reconcile). */
    private function applyBalances(): void
    {
        foreach ($this->sums as $accountId => $sum) {
            Account::ownedBy($this->owners[$accountId])->whereKey($accountId)->update(['balance' => DB::raw('opening_balance + '.(int) $sum)]);
        }
        $this->sums = [];
        $this->owners = [];
    }

    private function countFor(User $user): int
    {
        return Transaction::ownedBy($user)->count();
    }

    /** Hapus akun demo + user noise beserta datanya (transaksi dulu: FK akun tidak cascade). */
    private function purge(): void
    {
        $users = User::query()->where('email', self::DEMO_EMAIL)->orWhere('email', 'like', '%@'.self::NOISE_DOMAIN)->get();
        foreach ($users as $user) {
            Import::ownedBy($user)->delete();
            while (Transaction::ownedBy($user)->withTrashed()->limit(20_000)->forceDelete() > 0) {
                // per 20 ribu baris supaya satu DELETE tidak mengunci terlalu lama
            }
            PlanItem::ownedBy($user)->delete();
            PlanTemplate::ownedBy($user)->delete();
            $user->delete();
        }
        $this->line("Data seed-large lama dihapus ({$users->count()} user).");
    }

    /**
     * Pilih elemen berbobot (indeks 1 = bobot).
     *
     * @template T of array<int, mixed>
     *
     * @param  list<T>  $options
     * @return T
     */
    private static function weighted(array $options): array
    {
        $roll = mt_rand(1, (int) array_sum(array_column($options, 1)));
        foreach ($options as $option) {
            $roll -= $option[1];
            if ($roll <= 0) {
                return $option;
            }
        }

        return $options[array_key_last($options)];
    }
}
