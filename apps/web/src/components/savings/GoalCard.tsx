import { formatIDR } from '@personal-finance/shared';
import { ArrowRightLeft, History, Minus, Pencil, Plus, ShoppingBag } from 'lucide-react';
import type { SavingsGoal } from '@/api/types';
import { MoneyText } from '@/components/money/MoneyText';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { formatMonthlyNeeded, progressTone } from './savingsMeta';

export type GoalAction = 'add' | 'reduce' | 'move' | 'use' | 'history' | 'edit';

/** Satu tujuan: bar progres, angka, tenggat + perlu per bulan, dan aksi. */
export function GoalCard({ goal, canMove, onAction }: {
  goal: SavingsGoal;
  canMove: boolean;
  onAction: (action: GoalAction) => void;
}) {
  const monthly = formatMonthlyNeeded(goal);
  const tone = progressTone(goal);
  const icon = (label: string, action: GoalAction, Icon: typeof Plus) => (
    <Button variant="ghost" size="icon" className="size-8" aria-label={`${label} ${goal.name}`} onClick={() => onAction(action)}>
      <Icon className="size-4" />
    </Button>
  );

  return (
    <li className="grid gap-2 py-3">
      <div className="flex items-center justify-between gap-2">
        <span className="truncate text-sm font-medium">{goal.name}</span>
        <span className="text-sm font-medium tabular-nums">{goal.percent}%</span>
      </div>
      <div
        role="progressbar"
        aria-label={`Progres ${goal.name}`}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={Math.min(goal.percent, 100)}
        className="h-2 overflow-hidden rounded-full bg-muted"
      >
        <div className={cn('h-full rounded-full', tone === 'reached' ? 'bg-success' : 'bg-primary')} style={{ width: `${Math.min(goal.percent, 100)}%` }} />
      </div>
      <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <div className="grid gap-0.5 text-xs text-muted-foreground">
          <span><MoneyText amount={goal.allocated_amount} className="text-foreground" /> / {formatIDR(goal.target_amount)}</span>
          {(goal.target_date || monthly) && (
            <span>{[goal.target_date && `tenggat ${formatDate(goal.target_date, { month: 'short', year: 'numeric' })}`, monthly].filter(Boolean).join(' · ')}</span>
          )}
        </div>
        <div className="flex items-center">
          {icon('Tambah alokasi', 'add', Plus)}
          {icon('Kurangi alokasi', 'reduce', Minus)}
          {canMove && icon('Pindah alokasi', 'move', ArrowRightLeft)}
          {icon('Gunakan', 'use', ShoppingBag)}
          {icon('Riwayat', 'history', History)}
          {icon('Ubah', 'edit', Pencil)}
        </div>
      </div>
    </li>
  );
}
