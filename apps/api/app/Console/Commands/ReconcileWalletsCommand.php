<?php

namespace App\Console\Commands;

use App\Actions\Wallets\ReconcileWallets;
use App\Actions\Wallets\WalletMismatch;
use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\Wallet;
use App\Notifications\ReconcileMismatch;
use App\Reports\VisibleWallets;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

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
     * Wallet shared → owner household. Wallet private → pemiliknya saja (Keputusan #5: nama dan
     * saldo wallet privat tidak boleh sampai ke owner household, termasuk lewat notifikasi ini).
     *
     * @param  Collection<int, WalletMismatch>  $mismatches
     */
    private function notifyOwners(Collection $mismatches, bool $fixed): void
    {
        foreach ($mismatches->groupBy(fn (WalletMismatch $m) => $m->householdId) as $householdId => $rows) {
            $household = Household::find($householdId);
            if ($household === null) {
                continue;
            }

            $wallets = Wallet::query()->whereIn('id', $rows->map(fn (WalletMismatch $m) => $m->walletId))->get()->keyBy('id');
            $owners = $household->members()->wherePivot('role', HouseholdRole::Owner->value)->get();
            $privateOwners = $household->members()
                ->whereIn('users.id', $wallets->filter(fn (Wallet $w) => $w->isPrivate())->pluck('owner_user_id'))
                ->get();

            foreach ($owners->merge($privateOwners)->unique('id') as $recipient) {
                $visible = $rows->filter(function (WalletMismatch $m) use ($wallets, $recipient, $household) {
                    $wallet = $wallets->get($m->walletId);

                    return $wallet !== null && VisibleWallets::canSee($recipient, $wallet)
                        && ($wallet->isPrivate() || $household->isOwner($recipient));
                });

                if ($visible->isNotEmpty()) {
                    $recipient->notify(new ReconcileMismatch(
                        $household,
                        $visible->map(fn (WalletMismatch $m) => $m->toArray())->values()->all(),
                        $fixed,
                    ));
                }
            }
        }
    }
}
