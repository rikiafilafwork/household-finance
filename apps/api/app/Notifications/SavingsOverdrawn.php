<?php

namespace App\Notifications;

use App\Models\Account;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Rekening tabungan masuk kondisi "terpakai" (saldo < Σ alokasi). In-app saja (lonceng), sekali per
 * kejadian masuk kondisi — dijaga flag accounts.savings_overdrawn_at di CheckSavingsOverdrawn.
 */
class SavingsOverdrawn extends Notification
{
    use Queueable;

    public const TYPE = 'savings_overdrawn';

    public function __construct(
        public readonly Account $account,
        public readonly int $balance,
        public readonly int $allocated,
        public readonly int $shortfall,
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
        return self::TYPE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'account_id' => $this->account->id,
            'name' => $this->account->name,
            'balance' => $this->balance,
            'allocated' => $this->allocated,
            'shortfall' => $this->shortfall,
        ];
    }
}
