<?php

use Illuminate\Support\Facades\Schedule;

/*
| Jadwal (dijalankan container `scheduler`: php artisan schedule:work).
| Lock lewat cache `database`; jaminan sebenarnya tetap constraint DB.
*/

// Bukti harian bahwa saldo tidak meleset (ADR-002). Deteksi saja, tanpa --fix:
// selisih = bug yang harus diselidiki dulu, pemilik akun dinotifikasi in-app.
Schedule::command('accounts:reconcile')
    ->dailyAt('02:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();

// Rencana bulan baru dari template (Keputusan #9): tiap jam menit 10, hanya user yang di zona
// waktunya sedang tanggal 1 pukul 00.xx → ≈ 00:10 lokal, sekali per bulan. Idempoten.
Schedule::command('plan:generate --scheduled')
    ->hourlyAt(10)
    ->withoutOverlapping()
    ->onOneServer();
