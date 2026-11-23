<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Selisih saldo = bug. Dikirim ke pemilik akun, in-app saja (lonceng), tanpa email.
 */
class ReconcileMismatch extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{account_id: int, name: string, balance: int, expected: int, diff: int}>  $mismatches
     */
    public function __construct(
        public readonly array $mismatches,
        public readonly bool $fixed,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'reconcile_mismatch';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'mismatches' => $this->mismatches,
            'fixed' => $this->fixed,
        ];
    }
}
