<?php

namespace App\Notifications;

use App\Models\Household;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Selisih saldo = bug. Dikirim ke owner household (database + email).
 */
class ReconcileMismatch extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{wallet_id: int, name: string, balance: int, expected: int, diff: int}>  $mismatches
     */
    public function __construct(
        public readonly Household $household,
        public readonly array $mismatches,
        public readonly bool $fixed,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
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
            'household_id' => $this->household->id,
            'mismatches' => $this->mismatches,
            'fixed' => $this->fixed,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->error()
            ->subject("Selisih saldo terdeteksi di {$this->household->name}")
            ->line('Pengecekan harian menemukan saldo wallet yang tidak cocok dengan riwayat transaksinya:');

        foreach ($this->mismatches as $m) {
            $mail->line(sprintf('• %s: tercatat %s, seharusnya %s (selisih %s)',
                $m['name'], Money::format($m['balance']), Money::format($m['expected']), Money::format($m['diff'])));
        }

        return $mail->line($this->fixed
            ? 'Saldo sudah dibetulkan otomatis. Selisih tetap berarti ada bug yang perlu diselidiki.'
            : 'Jalankan `php artisan wallets:reconcile --fix` setelah penyebabnya diketahui.');
    }
}
