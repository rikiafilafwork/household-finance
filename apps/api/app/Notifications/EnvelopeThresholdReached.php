<?php

namespace App\Notifications;

use App\Models\PlanItem;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Amplop tembus 80% / 100%. In-app saja (lonceng web & mobile), tanpa email (Keputusan #10).
 */
class EnvelopeThresholdReached extends Notification
{
    use Queueable;

    public const TYPE = 'envelope_threshold_reached';

    public function __construct(
        public readonly PlanItem $envelope,
        public readonly int $threshold,
        public readonly int $spent,
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
            'plan_item_id' => $this->envelope->id,
            'name' => $this->envelope->name,
            'category_id' => $this->envelope->category_id,
            'month' => $this->envelope->month->format('Y-m'),
            'threshold' => $this->threshold,
            'spent' => $this->spent,
            'planned' => $this->envelope->planned_amount,
            'percent' => Money::percentOf($this->spent, $this->envelope->planned_amount),
        ];
    }
}
