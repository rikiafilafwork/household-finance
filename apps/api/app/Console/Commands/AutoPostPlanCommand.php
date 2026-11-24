<?php

namespace App\Console\Commands;

use App\Actions\Plan\AutoPostDueItems;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Centang otomatis item auto_post yang jatuh tempo (Keputusan #9). Aman dijalankan kapan pun dan
 * berulang: dijadwalkan tiap jam supaya tiap user terposting tak lama setelah tengah malam lokal.
 */
class AutoPostPlanCommand extends Command
{
    protected $description = 'Centang otomatis item rencana auto-post yang sudah jatuh tempo';

    protected $signature = 'plan:auto-post {--user= : Hanya user dengan id ini}';

    public function handle(AutoPostDueItems $autoPost): int
    {
        $now = CarbonImmutable::now();
        $posted = 0;
        $skipped = 0;

        User::query()
            ->when($this->option('user') !== null, fn ($q) => $q->whereKey((int) $this->option('user')))
            ->orderBy('id')
            ->chunkById(200, function (Collection $chunk) use ($autoPost, $now, &$posted, &$skipped) {
                /** @var User $user */
                foreach ($chunk as $user) {
                    $result = $autoPost->handle($user, $now);
                    $posted += count($result['posted']);
                    $skipped += count($result['skipped']);
                    foreach ($result['skipped'] as $itemId => $reason) {
                        $this->warn("User {$user->id}, item {$itemId} dilewati: {$reason}");
                    }
                }
            });

        $this->info("Selesai: {$posted} item dicentang otomatis, {$skipped} dilewati.");

        return self::SUCCESS;
    }
}
