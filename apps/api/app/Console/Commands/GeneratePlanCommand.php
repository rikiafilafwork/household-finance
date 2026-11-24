<?php

namespace App\Console\Commands;

use App\Actions\Plan\GeneratePlanFromTemplates;
use App\Models\User;
use App\Plan\PlanMonth;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Isi rencana dari template (Keputusan #9). Idempoten, jadi aman diulang.
 *
 *  - `--month=YYYY-MM`: bulan itu untuk semua user (atau `--user`).
 *  - tanpa `--month`: bulan berjalan di zona waktu masing-masing user.
 *  - `--scheduled`: hanya user yang di zona waktunya sedang tanggal 1 pukul 00.xx. Jadwal jalan tiap
 *    jam menit 10, jadi tiap user diproses sekali di awal bulannya (≈ 00:10 lokal) — item yang
 *    sengaja dihapus user tidak muncul lagi sepanjang bulan. Terlewat? Tombol "Buat dari template".
 */
class GeneratePlanCommand extends Command
{
    protected $description = 'Buat item rencana dari template aktif (idempoten)';

    protected $signature = 'plan:generate
        {--month= : Bulan YYYY-MM; default bulan berjalan di zona waktu user}
        {--user= : Hanya user dengan id ini}
        {--scheduled : Hanya user yang sedang di tanggal 1 pukul 00.xx waktu lokalnya}';

    public function handle(GeneratePlanFromTemplates $generate): int
    {
        $month = $this->option('month');
        if ($month !== null && preg_match('/^'.PlanMonth::ROUTE_PATTERN.'$/', (string) $month) !== 1) {
            $this->error('--month harus berformat YYYY-MM.');

            return self::INVALID;
        }

        $now = CarbonImmutable::now();
        $total = 0;
        $users = 0;

        User::query()
            ->when($this->option('user') !== null, fn ($q) => $q->whereKey((int) $this->option('user')))
            ->orderBy('id')
            ->chunkById(200, function (Collection $chunk) use ($generate, $month, $now, &$total, &$users) {
                /** @var User $user */
                foreach ($chunk as $user) {
                    $local = $now->setTimezone($user->timezone);
                    if ($this->option('scheduled') && ($local->day !== 1 || $local->hour !== 0)) {
                        continue;
                    }
                    $target = $month !== null ? PlanMonth::parse((string) $month) : PlanMonth::parse($local->format('Y-m'));
                    $created = $generate->handle($user, $target);
                    $total += $created;
                    $users++;
                    if ($created > 0) {
                        $this->line("User {$user->id}: {$created} item dibuat untuk {$target->key()}.");
                    }
                }
            });

        $this->info("Selesai: {$total} item dibuat untuk {$users} user.");

        return self::SUCCESS;
    }
}
