import type { BudgetItem } from '@/api/types';
import { MoneyText } from '@/components/money/MoneyText';
import { cn } from '@/lib/utils';

/** Warna mengikuti ambang alert (Keputusan #8): < 80 normal, 80–99 kuning, ≥ 100 merah. */
export function budgetTone(percent: number | null) {
  if (percent === null) return 'normal';
  if (percent >= 100) return 'over';
  if (percent >= 80) return 'warn';
  return 'normal';
}

export function ProgressBar({ percent, className }: { percent: number | null; className?: string }) {
  const tone = budgetTone(percent);
  const width = Math.min(percent ?? 0, 100);
  return (
    <div className={cn('h-2 w-full overflow-hidden rounded-full bg-muted', className)} role="progressbar" aria-valuenow={percent ?? 0} aria-valuemin={0} aria-valuemax={100}>
      <div
        className={cn('h-full rounded-full transition-[width]', tone === 'over' ? 'bg-destructive' : tone === 'warn' ? 'bg-warning' : 'bg-primary')}
        style={{ width: `${width}%` }}
      />
    </div>
  );
}

export function BudgetProgress({ item }: { item: BudgetItem }) {
  const tone = budgetTone(item.percent);
  return (
    <div className="grid gap-1.5">
      <div className="flex items-baseline justify-between gap-2 text-sm">
        <span className="flex min-w-0 items-center gap-2 font-medium">
          <span className="size-2.5 shrink-0 rounded-full" style={{ backgroundColor: item.category.color ?? 'var(--muted-foreground)' }} />
          <span className="truncate">{item.category.name}</span>
        </span>
        <span className={cn('shrink-0 tabular-nums text-muted-foreground', tone === 'over' && 'text-destructive', tone === 'warn' && 'text-foreground')}>
          {item.percent ?? 0}%
        </span>
      </div>
      <ProgressBar percent={item.percent} />
      <div className="flex justify-between text-xs text-muted-foreground">
        <span><MoneyText amount={item.spent} /> dari <MoneyText amount={item.limit_amount} /></span>
        <span>
          {item.remaining >= 0 ? <>sisa <MoneyText amount={item.remaining} /></> : <span className="text-destructive">lebih <MoneyText amount={-item.remaining} /></span>}
        </span>
      </div>
    </div>
  );
}
