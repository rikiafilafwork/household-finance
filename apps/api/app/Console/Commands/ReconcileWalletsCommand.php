<?php

namespace App\Console\Commands;

use App\Actions\Wallets\ReconcileWallets;
use App\Actions\Wallets\WalletMismatch;
use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Notifications\ReconcileMismatch;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class ReconcileWalletsCommand extends Command
{
    protected $description = 'Bandingkan saldo wallet dengan riwayat transaksi; --fix untuk membetulkan';

    protected $signature = 'wallets:reconcile
        {--household= : Hanya household dengan id ini}
        {--fix : Set balance = opening_balance + SUM(transaksi posted)}
        {--no-notify : Jangan kirim notifikasi ke owner}';

    public function handle(ReconcileWallets $reconcile): int
    {
        $householdId = $this->option('household') !== null ? (int) $this->option('household') : null;
        $mismatches = $reconcile->mismatches($householdId);

        if ($mismatches->isEmpty()) {
            $this->info('Selisih 0: semua saldo wallet cocok dengan riwayat transaksinya.');

            return self::SUCCESS;
        }

        $this->error("Ditemukan {$mismatches->count()} wallet dengan selisih saldo:");
        $this->table(
            ['Wallet', 'Household', 'Nama', 'Tercatat', 'Seharusnya', 'Selisih'],
            $mismatches->map(fn (WalletMismatch $m) => [
                $m->walletId, $m->householdId, $m->name,
                Money::format($m->balance), Money::format($m->expected), Money::format($m->diff()),
            ])->all(),
        );

        $fix = (bool) $this->option('fix');
        if ($fix) {
            foreach ($mismatches as $m) {
                $reconcile->fix($m->walletId);
            }
            $this->info('Dibetulkan. Jalankan lagi tanpa --fix untuk memastikan selisih 0.');
        }

        if (! $this->option('no-notify')) {
            $this->notifyOwners($mismatches, $fix);
        }

        // Kode keluar ≠ 0 supaya scheduler/CI/k6 menganggapnya kegagalan.
        return self::FAILURE;
    }

    /**
     * @param  Collection<int, WalletMismatch>  $mismatches
     */
    private function notifyOwners(Collection $mismatches, bool $fixed): void
    {
        foreach ($mismatches->groupBy(fn (WalletMismatch $m) => $m->householdId) as $householdId => $rows) {
            $household = Household::find($householdId);
            if ($household === null) {
                continue;
            }

            $owners = $household->members()->wherePivot('role', HouseholdRole::Owner->value)->get();
            Notification::send($owners, new ReconcileMismatch(
                $household,
                $rows->map(fn (WalletMismatch $m) => $m->toArray())->values()->all(),
                $fixed,
            ));
        }
    }
}
