<?php

namespace App\Notifications;

use App\Models\HouseholdInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email undangan. Token asli hanya hidup di sini (dan di email); DB menyimpan hash-nya.
 */
class InvitationSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly HouseholdInvitation $invitation,
        public readonly string $token,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function acceptUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/invitations/'.$this->token;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $household = $this->invitation->household;
        $inviter = $this->invitation->inviter;

        return (new MailMessage)
            ->subject("Undangan bergabung ke {$household->name}")
            ->greeting('Halo!')
            ->line("{$inviter->name} mengundang kamu bergabung ke household \"{$household->name}\" untuk mencatat keuangan bersama.")
            ->action('Terima undangan', $this->acceptUrl())
            ->line('Masuk atau daftar dengan email '.$this->invitation->email.' untuk menerima undangan ini.')
            ->line('Undangan berlaku sampai '.$this->invitation->expires_at->timezone($household->timezone)->translatedFormat('j F Y H:i').'.');
    }
}
