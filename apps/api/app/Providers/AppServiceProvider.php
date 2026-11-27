<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureAttributeNames();
    }

    /**
     * Nama field domain dalam pesan validasi Indonesia ("Nominal wajib diisi.", bukan
     * "Amount wajib diisi."). Di sini, bukan di lang/id/validation.php, supaya tidak tertimpa
     * saat file bahasa diperbarui (`php artisan lang:update`).
     */
    private function configureAttributeNames(): void
    {
        // addLines() menandai grup 'validation' sudah dimuat; muat file-nya dulu supaya tidak terlewat.
        app('translator')->load('*', 'validation', 'id');
        Lang::addLines(collect([
            'amount' => 'nominal',
            'opening_balance' => 'saldo awal',
            'actual_balance' => 'saldo sebenarnya',
            'sort_order' => 'urutan',
            'account_id' => 'rekening',
            'from_account_id' => 'rekening asal',
            'to_account_id' => 'rekening tujuan',
            'category_id' => 'kategori',
            'occurred_on' => 'tanggal',
            'note' => 'catatan',
            'type' => 'jenis',
            'kind' => 'jenis',
            'color' => 'warna',
            'icon' => 'ikon',
            'timezone' => 'zona waktu',
            'planned_amount' => 'nominal rencana',
            'due_on' => 'jatuh tempo',
            'mode' => 'mode',
            'name' => 'nama',
            'current_password' => 'password saat ini',
        ])->mapWithKeys(fn (string $name, string $field) => ["validation.attributes.{$field}" => $name])->all(), 'id');
    }

    private function configureRateLimiting(): void
    {
        // Login: 5x per menit per (email + IP), plus batas kasar per IP terhadap tebak banyak email.
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(20)->by('ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(10)->by($request->ip()));

        // Upload import: tiap file memicu job parse; cukup untuk beberapa percobaan pemetaan kolom.
        RateLimiter::for('imports', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->getAuthIdentifier()));
        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->getAuthIdentifier()));
    }
}
