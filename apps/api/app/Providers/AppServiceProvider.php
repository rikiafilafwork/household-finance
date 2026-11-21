<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
