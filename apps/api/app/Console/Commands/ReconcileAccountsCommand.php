<?php

namespace App\Console\Commands;

use App\Actions\Accounts\AccountMismatch;
use App\Actions\Accounts\ReconcileAccounts;
use App\Models\User;
use App\Notifications\ReconcileMismatch;
use App\Support\Money;
use Illuminate\Console\Command;

class ReconcileAccountsCommand extends Command
{
    protected $description = 'Bandingkan saldo akun dengan riwayat transaksi; --fix untuk membetulkan';

    protected $signature = 'accounts:reconcile
        {--user= : Hanya akun milik user dengan id ini}
        {--fix : Set balance = opening_balance + SUM(transaksi yang belum dihapus)}
        {--no-notify : Jangan kirim notifikasi in-app ke pemilik akun}';

    public function handle(ReconcileAccounts $reconcile): int
    {
        $userId = $this->option('user') !== null ? (int) $this->option('user') : null;
        $mismatches = $reconcile->mismatches($userId);

        if ($mismatches->isEmpty()) {
            $this->info('Selisih 0: semua saldo akun cocok dengan riwayat transaksinya.');

            return self::SUCCESS;
        }

        $this->error("Ditemukan {$mismatches->count()} akun dengan selisih saldo:");
        $this->table(
            ['Akun', 'User', 'Nama', 'Tercatat', 'Seharusnya', 'Selisih'],
            $mismatches->map(fn (AccountMismatch $m) => [
                $m->accountId, $m->userId, $m->name,
                Money::format($m->balance), Money::format($m->expected), Money::format($m->diff()),
            ])->all(),
        );

        $fix = (bool) $this->option('fix');
        if ($fix) {
            foreach ($mismatches as $m) {
                $reconcile->fix($m->accountId);
            }
            $this->info('Dibetulkan. Jalankan lagi tanpa --fix untuk memastikan selisih 0.');
        }

        if (! $this->option('no-notify')) {
            // Tiap user hanya menerima akun miliknya sendiri (Keputusan #5).
            foreach ($mismatches->groupBy(fn (AccountMismatch $m) => $m->userId) as $ownerId => $rows) {
                User::find($ownerId)?->notify(new ReconcileMismatch(
                    $rows->map(fn (AccountMismatch $m) => $m->toArray())->values()->all(),
                    $fix,
                ));
            }
        }

        // Kode keluar ≠ 0 supaya scheduler/CI/k6 menganggapnya kegagalan.
        return self::FAILURE;
    }
}
