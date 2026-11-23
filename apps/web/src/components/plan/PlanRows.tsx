import { Check, EllipsisVertical } from 'lucide-react';
import type { PlanItem } from '@/api/plan';
import type { Category } from '@/api/types';
import { CategoryIcon } from '@/components/categories/categoryMeta';
import { MoneyText } from '@/components/money/MoneyText';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatDate } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { checklistDiff, envelopeTone, isBadDiff } from './planMeta';

export type RowAction = 'check' | 'uncheck' | 'edit' | 'skip' | 'unskip' | 'delete';

function RowMenu({ item, onAction }: { item: PlanItem; onAction: (a: RowAction) => void }) {
  const done = item.status === 'done';
  const skipped = item.status === 'skipped';
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" className="size-8 shrink-0" aria-label={`Aksi ${item.name}`}>
          <EllipsisVertical className="size-4" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem onSelect={() => onAction('edit')}>Ubah</DropdownMenuItem>
        {done && item.mode === 'checklist' && <DropdownMenuItem onSelect={() => onAction('uncheck')}>Batal centang</DropdownMenuItem>}
        {!done && (skipped
          ? <DropdownMenuItem onSelect={() => onAction('unskip')}>Masukkan lagi ke rencana</DropdownMenuItem>
          : <DropdownMenuItem onSelect={() => onAction('skip')}>Lewati bulan ini</DropdownMenuItem>)}
        {!done && (
          <>
            <DropdownMenuSeparator />
            <DropdownMenuItem variant="destructive" onSelect={() => onAction('delete')}>Hapus</DropdownMenuItem>
          </>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

function Meta({ item, category }: { item: PlanItem; category?: Category }) {
  const parts = [category?.name, item.due_on && item.status !== 'done' ? `jatuh tempo ${formatDate(item.due_on, { day: 'numeric', month: 'short' })}` : null,
    item.transaction ? `dicatat ${formatDate(item.transaction.occurred_on, { day: 'numeric', month: 'short' })}` : null].filter(Boolean);
  return <span className="truncate text-xs text-muted-foreground">{parts.join(' · ')}</span>;
}

/** Baris checklist: Rencana / Aktual / Selisih. Selesai = tercoret; dilewati = redup. */
export function ChecklistRow({ item, category, onAction }: { item: PlanItem; category?: Category; onAction: (a: RowAction) => void }) {
  const done = item.status === 'done';
  const skipped = item.status === 'skipped';
  const diff = checklistDiff(item);

  return (
    <li data-testid={`plan-item-${item.id}`} data-status={item.status} className={cn('flex items-center gap-3 py-2.5', skipped && 'opacity-60')}>
      <button
        type="button"
        role="checkbox"
        aria-checked={done}
        aria-label={done ? `Batal centang ${item.name}` : `Centang ${item.name}`}
        disabled={skipped}
        onClick={() => onAction(done ? 'uncheck' : 'check')}
        className={cn(
          'flex size-6 shrink-0 items-center justify-center rounded-md border-2 transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed',
          done ? 'border-primary bg-primary text-primary-foreground' : 'border-muted-foreground/40 hover:border-primary',
        )}
      >
        {done && <Check className="size-4" strokeWidth={3} />}
      </button>
      <div className="grid min-w-0 flex-1">
        <span className={cn('flex items-center gap-2 truncate text-sm font-medium', done && 'text-muted-foreground line-through')}>
          {item.name}
          {skipped && <Badge variant="secondary" className="no-underline">Dilewati</Badge>}
        </span>
        <Meta item={item} category={category} />
        {/* Ponsel: angka ringkas di bawah nama. */}
        <span className="text-xs text-muted-foreground md:hidden">
          {done ? <><MoneyText amount={item.actual} /> dari <MoneyText amount={item.planned_amount} /></> : <MoneyText amount={item.planned_amount} />}
        </span>
      </div>
      <div className="hidden w-28 text-right text-sm md:block"><MoneyText amount={item.planned_amount} className="text-muted-foreground" /></div>
      <div className="hidden w-28 text-right text-sm md:block">{done ? <MoneyText amount={item.actual} className="font-medium" /> : <span className="text-muted-foreground">—</span>}</div>
      <div className="hidden w-28 text-right text-sm md:block" data-testid={`plan-diff-${item.id}`}>
        {diff === null || diff === 0
          ? <span className="text-muted-foreground">{diff === 0 ? 'pas' : '—'}</span>
          : <span className={isBadDiff(item, diff) ? 'text-destructive' : 'text-success'}>{diff > 0 ? '+' : '−'}<MoneyText amount={Math.abs(diff)} /></span>}
      </div>
      <RowMenu item={item} onAction={onAction} />
    </li>
  );
}

const barColor = { normal: 'bg-primary', warning: 'bg-amber-500', over: 'bg-destructive' } as const;

/** Baris amplop: aktual dari transaksi kategori bulan ini (tanpa transaksi centang), progress bar. */
export function EnvelopeRow({ item, category, onAction }: { item: PlanItem; category?: Category; onAction: (a: RowAction) => void }) {
  const skipped = item.status === 'skipped';
  const tone = envelopeTone(item.percent);
  const rest = item.planned_amount - item.actual;

  return (
    <li data-testid={`plan-item-${item.id}`} data-status={item.status} className={cn('flex items-center gap-3 py-3', skipped && 'opacity-60')}>
      {category ? <CategoryIcon category={category} className="size-6" /> : <span className="size-6" />}
      <div className="grid min-w-0 flex-1 gap-1.5">
        <div className="flex items-baseline justify-between gap-2">
          <span className="flex items-center gap-2 truncate text-sm font-medium">
            {item.name}
            {skipped && <Badge variant="secondary">Dilewati</Badge>}
          </span>
          <span className="text-sm whitespace-nowrap">
            <MoneyText amount={item.actual} className="font-medium" /> <span className="text-muted-foreground">/ <MoneyText amount={item.planned_amount} /></span>
          </span>
        </div>
        {!skipped && (
          <>
            <div
              role="progressbar"
              aria-label={`Terpakai ${item.name}`}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-valuenow={Math.min(item.percent ?? 0, 100)}
              data-tone={tone}
              className="h-2 overflow-hidden rounded-full bg-muted"
            >
              <div className={cn('h-full rounded-full transition-all', barColor[tone])} style={{ width: `${Math.min(item.percent ?? 0, 100)}%` }} />
            </div>
            <span className={cn('text-xs', tone === 'over' ? 'text-destructive' : tone === 'warning' ? 'text-amber-700 dark:text-amber-500' : 'text-muted-foreground')}>
              {item.percent ?? 0}% · {rest >= 0 ? <>sisa <MoneyText amount={rest} /></> : <>lebih <MoneyText amount={-rest} /></>}
            </span>
          </>
        )}
      </div>
      <RowMenu item={item} onAction={onAction} />
    </li>
  );
}

/** Baris "di luar rencana": transaksi tanpa item/amplop. Bukan item, jadi tanpa aksi. */
export function UnplannedRow({ label, amount, hint }: { label: string; amount: number; hint: string }) {
  return (
    <li data-testid="plan-unplanned" className="flex items-center gap-3 py-2.5">
      <span className="size-6" />
      <div className="grid min-w-0 flex-1">
        <span className="text-sm font-medium">{label}</span>
        <span className="text-xs text-muted-foreground">{hint}</span>
      </div>
      <MoneyText amount={amount} className="text-sm font-medium" />
      <span className="size-8" />
    </li>
  );
}
