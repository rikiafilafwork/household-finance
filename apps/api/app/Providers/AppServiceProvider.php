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
            'limit_amount' => 'limit',
            'wallet_id' => 'wallet',
            'from_wallet_id' => 'wallet asal',
            'to_wallet_id' => 'wallet tujuan',
            'category_id' => 'kategori',
            'occurred_on' => 'tanggal',
            'note' => 'catatan',
            'type' => 'jenis',
            'kind' => 'jenis',
            'visibility' => 'visibilitas',
            'color' => 'warna',
            'icon' => 'ikon',
            'role' => 'peran',
            'household_name' => 'nama household',
            'items' => 'daftar budget',
            'items.*.category_id' => 'kategori',
            'items.*.limit_amount' => 'limit',
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

        // Token undangan tidak bisa ditebak (48 char), limiter ini hanya pagar tambahan.
        RateLimiter::for('invitation-token', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // Mencegah household dipakai untuk spam email.
        RateLimiter::for('invitations', fn (Request $request) => Limit::perHour(20)->by((string) $request->user()?->getAuthIdentifier()));
    }
}
