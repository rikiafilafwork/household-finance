<?php

use Illuminate\Support\Facades\Schedule;

/*
| Jadwal (dijalankan container `scheduler`: php artisan schedule:work).
| Lock lewat cache `database`; jaminan sebenarnya tetap constraint DB.
*/

// Bukti harian bahwa saldo tidak meleset (ADR-002). Deteksi saja, tanpa --fix:
// selisih = bug yang harus diselidiki dulu, owner dinotifikasi.
Schedule::command('wallets:reconcile')
    ->dailyAt('02:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();
