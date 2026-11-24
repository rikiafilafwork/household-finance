<?php

namespace App\Console\Commands;

use App\Actions\Accounts\CreateAccount;
use App\Actions\Plan\CheckPlanItem;
use App\Actions\Plan\CheckPlanItemInput;
use App\Actions\Plan\GeneratePlanFromTemplates;
use App\Actions\Plan\PlanTemplateInput;
use App\Actions\Plan\SavePlanTemplate;
use App\Actions\Transactions\CreateTransaction;
use App\Actions\Transactions\TransactionInput;
use App\Actions\Transfers\CreateTransfer;
use App\Actions\Transfers\TransferInput;
use App\Enums\AccountType;
use App\Enums\CategoryKind;
use App\Enums\PlanItemType;
use App\Enums\PlanMode;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\User;
use App\Plan\PlanMonth;
use App\Support\DefaultCategories;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Akun demo untuk mencoba aplikasi (mis. lewat tunnel): 4 akun, template bulanan, rencana 6 bulan
 * terakhir dengan centang, transaksi harian, transfer. Semua lewat Actions asli → saldo konsisten
 * (reconcile 0). Password acak ditulis ke storage/app/private (di-gitignore), tidak dicetak.
 * Data deterministik (seed tetap) supaya mudah dibandingkan antar-reset. Ditolak di production.
 */
class DemoUserCommand extends Command
{
    public const CREDENTIALS_FILE = 'demo-credentials.txt';

    protected $description = 'Buat (ulang) user demo berisi data contoh 6 bulan; password acak ke storage/app/private';

    protected $signature = 'demo:user
        {--email=demo@example.test : Email user demo}
        {--fresh : Hapus user demo yang sudah ada lalu buat ulang}';

    private User $user;

    /** @var array<string, Account> */
    private array $acc = [];

    /** @var array<string, int> kunci "kind:nama" */
    private array $cat = [];

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('demo:user tidak boleh dijalankan di production.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->option('email')));
        $existing = User::where('email', $email)->first();
        if ($existing !== null) {
            if (! $this->option('fresh')) {
                $this->error("User {$email} sudah ada. Pakai --fresh untuk membuat ulang.");

                return self::FAILURE;
            }
            $this->deleteUser($existing);
        }

        mt_srand(2026);
        $password = Str::password(24, symbols: false);
        $this->user = User::create(['name' => 'Demo', 'email' => $email, 'password' => $password, 'timezone' => 'Asia/Jakarta']);
        DefaultCategories::seedFor($this->user);
        $this->cat = Category::ownedBy($this->user)->get()
            ->mapWithKeys(fn (Category $c) => ["{$c->kind->value}:{$c->name}" => (int) $c->getKey()])->all();

        $today = CarbonImmutable::now($this->user->timezone)->startOfDay();
        $this->seedAccounts();
        $this->seedTemplates();
        foreach ([5, 4, 3, 2, 1, 0] as $back) {
            $this->seedMonth(PlanMonth::parse($today->subMonthsNoOverflow($back)->format('Y-m')), $today);
        }

        Storage::disk('local')->put(self::CREDENTIALS_FILE, "email: {$email}\npassword: {$password}\n");
        $this->info("User demo {$email} siap.");
        $this->line('Kredensial: '.Storage::disk('local')->path(self::CREDENTIALS_FILE));

        return self::SUCCESS;
    }

    private function deleteUser(User $user): void
    {
        DB::transaction(function () use ($user) {
            // FK ke accounts tidak cascade; hapus dulu sebelum akun ter-cascade dari user.
            foreach (['transactions', 'plan_items', 'plan_templates'] as $table) {
                DB::table($table)->where('user_id', $user->getKey())->delete();
            }
            DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->getKey())->delete();
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
            $user->tokens()->delete();
            $user->delete();
        });
    }

    private function seedAccounts(): void
    {
        $create = app(CreateAccount::class);
        foreach ([
            ['BCA', AccountType::Bank, 6_500_000],
            ['Bank Jago', AccountType::Bank, 2_000_000],
            ['GoPay', AccountType::Ewallet, 150_000],
            ['Dompet', AccountType::Cash, 300_000],
        ] as [$name, $type, $opening]) {
            $this->acc[$name] = $create->handle($this->user, $name, $type, $opening);
        }
    }

    private function seedTemplates(): void
    {
        $save = app(SavePlanTemplate::class);
        $bca = (int) $this->acc['BCA']->getKey();
        $jago = (int) $this->acc['Bank Jago']->getKey();
        $templates = [
            [PlanItemType::Income, PlanMode::Checklist, 'Gaji', 'income:Gaji', 9_000_000, $bca, 25, false],
            [PlanItemType::Income, PlanMode::Envelope, 'Freelance', 'income:Freelance', 1_500_000, null, null, false],
            [PlanItemType::Expense, PlanMode::Checklist, 'Cicilan motor', 'expense:Tagihan', 1_250_000, $bca, 5, true],
            [PlanItemType::Expense, PlanMode::Checklist, 'Internet', 'expense:Tagihan', 375_000, $bca, 10, false],
            [PlanItemType::Expense, PlanMode::Checklist, 'BPJS', 'expense:Kesehatan', 150_000, $jago, 15, false],
            [PlanItemType::Expense, PlanMode::Checklist, 'Listrik', 'expense:Tagihan', 450_000, $bca, 20, false],
            [PlanItemType::Expense, PlanMode::Envelope, 'Makan', 'expense:Makan', 1_500_000, null, null, false],
            [PlanItemType::Expense, PlanMode::Envelope, 'Transport', 'expense:Transport', 700_000, null, null, false],
            [PlanItemType::Expense, PlanMode::Envelope, 'Hiburan', 'expense:Hiburan', 400_000, null, null, false],
            [PlanItemType::Expense, PlanMode::Envelope, 'Belanja', 'expense:Belanja', 800_000, null, null, false],
        ];
        foreach ($templates as [$type, $mode, $name, $cat, $amount, $account, $day, $autoPost]) {
            $save->create($this->user, new PlanTemplateInput(
                type: $type, mode: $mode, name: $name, categoryId: $this->cat[$cat], plannedAmount: $amount,
                accountId: $account, dayOfMonth: $day, autoPost: $autoPost,
            ));
        }
    }

    /**
     * Bulan lalu: semua checklist dicentang pada tanggal jatuh tempo. Bulan berjalan: hanya yang
     * jatuh tempo ≥ 5 hari lalu, sehingga Beranda punya item "terlambat" dan "segera".
     */
    private function seedMonth(PlanMonth $month, CarbonImmutable $today): void
    {
        app(GeneratePlanFromTemplates::class)->handle($this->user, $month);
        $first = CarbonImmutable::parse($month->key().'-01');
        $isCurrent = $month->key() === $today->format('Y-m');
        $lastDay = $isCurrent ? $today->day : $first->daysInMonth;
        $date = fn (int $day) => $first->setDay(min($day, $lastDay))->toDateString();

        $check = app(CheckPlanItem::class);
        $items = PlanItem::ownedBy($this->user)->forMonth($month->firstDay())->where('mode', PlanMode::Checklist->value)->get();
        foreach ($items as $item) {
            $due = $item->due_on;
            if ($due === null || ($isCurrent && $due->greaterThan($today->subDays(5)))) {
                continue;
            }
            // Listrik berubah tiap bulan; sisanya sesuai rencana.
            $amount = $item->name === 'Listrik' ? mt_rand(38, 52) * 10_000 : $item->planned_amount;
            $check->handle($this->user, $item, new CheckPlanItemInput(clientId: (string) Str::uuid(), amount: $amount, occurredOn: $due->toDateString()));
        }

        for ($day = 1; $day <= $lastDay; $day++) {
            $this->expense('Dompet', 'Makan', mt_rand(3, 7) * 5_000, $date($day), 'Makan siang');
            if (mt_rand(1, 100) <= 55) {
                $this->expense('GoPay', 'Makan', mt_rand(4, 12) * 5_000, $date($day), 'GoFood');
            }
            if (mt_rand(1, 100) <= 60) {
                $this->expense('GoPay', 'Transport', mt_rand(3, 9) * 5_000, $date($day), 'Ojek');
            }
            if ($day % 7 === 6) {
                $this->expense('BCA', 'Belanja', mt_rand(15, 30) * 10_000, $date($day), 'Belanja mingguan');
            }
            if ($day % 10 === 2) {
                $this->expense('GoPay', 'Hiburan', mt_rand(5, 15) * 10_000, $date($day), 'Nonton / langganan');
            }
            if ($day === 1 || $day === 15) {
                $this->transfer('BCA', 'GoPay', 800_000, $date($day), 'Top up GoPay');
                $this->transfer('BCA', 'Dompet', 400_000, $date($day), 'Tarik tunai');
            }
        }
        // Freelance (amplop pemasukan) dan satu pengeluaran di luar rencana per bulan.
        $this->income('Bank Jago', 'Freelance', mt_rand(6, 14) * 100_000, $date(12), 'Proyek desain');
        $this->expense('BCA', 'Kesehatan', mt_rand(8, 20) * 10_000, $date(9), 'Apotek');
        if ($isCurrent) {
            $this->expense('BCA', 'Hiburan', 350_000, $date(8), 'Tiket konser'); // amplop Hiburan jebol bulan ini
        }
    }

    private function expense(string $account, string $category, int $amount, string $date, string $note): void
    {
        $this->transaction($account, TransactionType::Expense, CategoryKind::Expense, $category, $amount, $date, $note);
    }

    private function income(string $account, string $category, int $amount, string $date, string $note): void
    {
        $this->transaction($account, TransactionType::Income, CategoryKind::Income, $category, $amount, $date, $note);
    }

    private function transaction(string $account, TransactionType $type, CategoryKind $kind, string $category, int $amount, string $date, string $note): void
    {
        app(CreateTransaction::class)->handle($this->user, new TransactionInput(
            accountId: (int) $this->acc[$account]->getKey(), type: $type, amount: $amount, occurredOn: $date,
            categoryId: $this->cat["{$kind->value}:{$category}"], note: $note, clientId: (string) Str::uuid(),
        ));
    }

    private function transfer(string $from, string $to, int $amount, string $date, string $note): void
    {
        app(CreateTransfer::class)->handle($this->user, new TransferInput(
            fromAccountId: (int) $this->acc[$from]->getKey(), toAccountId: (int) $this->acc[$to]->getKey(),
            amount: $amount, occurredOn: $date, note: $note, clientId: (string) Str::uuid(),
        ));
    }
}
