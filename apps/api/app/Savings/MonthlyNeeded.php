<?php

namespace App\Savings;

use Carbon\CarbonImmutable;

/**
 * "Perlu per bulan" untuk satu tujuan: fungsi murni, tanpa jam sistem (today diberikan pemanggil
 * dalam zona waktu user).
 */
final class MonthlyNeeded
{
    /**
     * null = tanpa tenggat, 0 = sudah tercapai. Selain itu ceil(sisa / bulan), dengan bulan = bulan
     * kalender dari bulan $today sampai bulan tenggat (inklusif, minimal 1; tenggat lewat = 1).
     */
    public static function for(int $target, int $allocated, ?string $targetDate, string $today): ?int
    {
        if ($targetDate === null) {
            return null;
        }

        $remaining = $target - $allocated;
        if ($remaining <= 0) {
            return 0;
        }

        $from = CarbonImmutable::parse($today);
        $to = CarbonImmutable::parse($targetDate);
        $months = max(1, ($to->year - $from->year) * 12 + ($to->month - $from->month) + 1);

        return intdiv($remaining + $months - 1, $months);
    }
}
