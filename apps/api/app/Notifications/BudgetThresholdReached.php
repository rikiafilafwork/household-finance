<?php

namespace App\Notifications;

use App\Models\Budget;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Dikirim dari job CheckBudgetThresholds (sudah di antrean), jadi notifikasi ini sendiri sinkron.
 */
class BudgetThresholdReached extends Notification
{
    use Queueable;

    public const TYPE = 'budget_threshold_reached';

    public function __construct(
        public readonly Budget $budget,
        public readonly int $threshold,
        public readonly int $spent,
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
        return self::TYPE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'household_id' => $this->budget->household_id,
            'budget_id' => $this->budget->id,
            'category' => ['id' => $this->budget->category->id, 'name' => $this->budget->category->name],
            'month' => $this->budget->month->format('Y-m'),
            'threshold' => $this->threshold,
            'spent' => $this->spent,
            'limit_amount' => $this->budget->limit_amount,
            'percent' => Money::percentOf($this->spent, $this->budget->limit_amount),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $category = $this->budget->category->name;
        $month = $this->budget->month->locale('id')->translatedFormat('F Y');
        $percent = Money::percentOf($this->spent, $this->budget->limit_amount);

        $mail = (new MailMessage)
            ->subject($this->threshold >= 100
                ? "Budget {$category} {$month} sudah habis"
                : "Budget {$category} {$month} sudah terpakai {$percent}%")
            ->greeting('Halo!')
            ->line(sprintf('Pengeluaran %s di %s untuk %s: %s dari budget %s (%d%%).',
                $category, $this->budget->household->name, $month,
                Money::format($this->spent), Money::format($this->budget->limit_amount), $percent));

        if ($this->threshold >= 100) {
            $mail->error()->line('Budget-nya sudah terlewati. Pertimbangkan menahan pengeluaran atau menaikkan limit.');
        } else {
            $mail->line('Sisa: '.Money::format($this->budget->limit_amount - $this->spent).'.');
        }

        return $mail->action('Lihat budget', rtrim((string) config('app.url'), '/').'/budgets/'.$this->budget->month->format('Y-m'));
    }
}
