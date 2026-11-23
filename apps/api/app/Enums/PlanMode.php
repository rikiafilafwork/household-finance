<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Mode item rencana (Keputusan #7):
 *  - checklist: satu kali per bulan, aktual = SATU transaksi yang terhubung (centang).
 *  - envelope: amplop per kategori, aktual = transaksi kategori itu yang tidak terhubung ke checklist.
 */
enum PlanMode: string
{
    use HasValues;

    case Checklist = 'checklist';
    case Envelope = 'envelope';
}
